<?php

namespace App\Support;

/**
 * 外链访问安全防护：
 * 后台填写的外链地址需要由服务器主动请求以检测可访问性，
 * 必须阻止 http(s) 以外的协议，以及指向内网/回环/保留地址的主机，
 * 防止管理员误填（或被利用）发起 SSRF 请求。
 */
class NetworkGuard
{
    public static function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        return self::isPublicHost($parts['host']);
    }

    public static function isPublicHost(string $host): bool
    {
        $host = trim($host, '[]'); // 去掉 IPv6 字面量的方括号
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || $host === 'localhost') {
            return false;
        }

        // 显式本地域名后缀
        if (str_ends_with($host, '.localhost') || $host === 'local') {
            return false;
        }

        // IP 字面量直接判定
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        // 主机名非法字符判定（防止 user@evil 等绕过）
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }

        // 域名：解析出全部 A/AAAA 记录逐一校验，避免域名解析到内网
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            // 无法解析的主机视为不可访问，不通过
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // FILTER_FLAG 覆盖私有网段、保留网段、回环地址等
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $public !== false;
    }

    /**
     * @return string[]
     */
    protected static function resolveHost(string $host): array
    {
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $records = @dns_get_record($host, DNS_A);
            foreach ((array) $records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $records = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        } else {
            $a = @dns_get_record($host, DNS_A);
            foreach ((array) $a as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $aaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // 兜底：gethostbyname（部分环境 dns_get_record 不可用）
        if (empty($ips)) {
            $resolved = @gethostbyname($host);
            if ($resolved && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ips[] = $resolved;
            }
        }

        return array_unique($ips);
    }
}
