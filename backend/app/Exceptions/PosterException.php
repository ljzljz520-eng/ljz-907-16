<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 海报管理相关的业务异常，控制器统一转为 422 响应。
 */
class PosterException extends RuntimeException
{
}
