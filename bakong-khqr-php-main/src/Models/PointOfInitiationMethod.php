<?php

declare(strict_types=1);

namespace KHQR\Models;

use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\EMV;

class PointOfInitiationMethod extends TagLengthString
{
    public function __construct(string $tag, string $value)
    {
        if (strlen($value) > 2) {
            throw new KHQRException(KHQRException::POINT_INITIATION_LENGTH_INVALID);
        }

        if ($value !== EMV::STATIC_QR && $value !== EMV::DYNAMIC_QR) {
            throw new KHQRException(KHQRException::POINT_OF_INITIATION_METHOD_INVALID);
        }

        parent::__construct($tag, $value);
    }
}
