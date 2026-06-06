<?php

declare(strict_types=1);

namespace KHQR\Models;

use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\EMV;

class Timestamp extends TagLengthString
{
    public function __construct(string $tag, ?TimestampData $timestampData, ?string $poi = null)
    {
        $creationTimestamp = $timestampData->creationTimestamp ?? null;
        $expirationTimestamp = $timestampData->expirationTimestamp ?? null;

        if ($poi === EMV::DYNAMIC_QR) {
            if (is_null($timestampData) || is_null($expirationTimestamp)) {
                throw new KHQRException(KHQRException::EXPIRATION_TIMESTAMP_REQUIRED);
            }

            if (strlen($timestampData->expirationTimestamp ?? '') !== EMV::INVALID_LENGTH_TIMESTAMP) {
                throw new KHQRException(KHQRException::EXPIRATION_TIMESTAMP_LENGTH_INVALID);
            }

            if (! ctype_digit($timestampData->expirationTimestamp ?? '')) {
                throw new KHQRException(KHQRException::INVALID_DYNAMIC_KHQR);
            }

            if ($expirationTimestamp < $creationTimestamp) {
                throw new KHQRException(KHQRException::EXPIRATION_TIMESTAMP_IN_THE_PAST);
            }

            $currentTimestampInMilliseconds = strval(floor(microtime(true) * 1000));
            if ($expirationTimestamp < $currentTimestampInMilliseconds) {
                throw new KHQRException(KHQRException::KHQR_EXPIRED);
            }
        }

        $timestampString = '';

        $createdTimestamp = new TimestampMillisecond(EMV::CREATION_TIMESTAMP, (string) $creationTimestamp);
        $timestampString .= $createdTimestamp;

        $expiredTimestamp = new TimestampMillisecond(EMV::EXPIRATION_TIMESTAMP, (string) $expirationTimestamp);
        $timestampString .= $expiredTimestamp;

        parent::__construct($tag, $timestampString);
    }
}

class TimestampMillisecond extends TagLengthString
{
    public function __construct(string $tag, string $value)
    {
        parent::__construct($tag, $value);
    }
}
