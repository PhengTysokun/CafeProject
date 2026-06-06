<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\EMV;
use KHQR\Helpers\Utils;
use KHQR\Models\SourceInfo;
use KHQR\Models\Timestamp;
use KHQR\Models\TimestampData;
use PHPUnit\Framework\TestCase;

class DeepLinkTest extends TestCase
{
    private function test_deep_link($data): void
    {
        $currentTimestampInMilliseconds = floor(microtime(true) * 1000);
        $timestampData = new TimestampData(strval($currentTimestampInMilliseconds), strval($currentTimestampInMilliseconds + 60 * 1000));
        $timestamp = new Timestamp(EMV::TIMESTAMP_TAG, $timestampData, EMV::DYNAMIC_QR);

        try {
            $khqr = $data['data'][1].$timestamp.EMV::CRC.EMV::CRC_LENGTH;
            $khqr .= Utils::crc16($khqr);

            $response = BakongKHQR::generateDeepLinkWithUrl($data['data'][0], $khqr, $data['data'][2] ?? null);
            $this->assertEquals($data['errorCode'], $response->status['errorCode'], $data['statement']);
        } catch (KHQRException $e) {
            $this->assertEquals($data['errorCode'], $e->getCode(), $data['statement']);
        }
    }

    public function test_deep_link_invalid_url(): void
    {
        $data = [
            'statement' => 'Invalid DeepLink URL',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/deeplink',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
            ],
            'errorCode' => 29,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_source_info_null_callback(): void
    {
        $data = [
            'statement' => 'Null callback source info',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/v1/generate_deeplink_by_qr',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
                new SourceInfo(
                    'https://sit-api-bakong.nbc.gov.kh/khqr.png',
                    'KHQR',
                    null
                ),
            ],
            'errorCode' => 14,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_source_info_null_icon(): void
    {
        $data = [
            'statement' => 'Null icon source info',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/v1/generate_deeplink_by_qr',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
                new SourceInfo(
                    null,
                    'KHQR',
                    'https://sit-api-bakong.nbc.gov.kh/callback'
                ),
            ],
            'errorCode' => 14,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_source_info_null_name(): void
    {
        $data = [
            'statement' => 'Null name source info',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/v1/generate_deeplink_by_qr',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
                new SourceInfo(
                    'https://sit-api-bakong.nbc.gov.kh/khqr.png',
                    null,
                    'https://sit-api-bakong.nbc.gov.kh/callback'
                ),
            ],
            'errorCode' => 14,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_invalid_qr(): void
    {
        $data = [
            'statement' => 'Invalid QR',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/v1/generate_deeplink_by_qr',
                '00020101021029190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
            ],
            'errorCode' => 8,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_timeout(): void
    {
        $data = [
            'statement' => 'Deep link timeout',
            'data' => [
                'https://fake-deeplink-api.herokuapp.com/v1/generate_deeplink_by_qr',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
            ],
            'errorCode' => 13,
        ];
        self::test_deep_link($data);
    }

    public function test_deep_link_success(): void
    {
        $data = [
            'statement' => 'Generating DeepLink successfully',
            'data' => [
                'https://sit-api-bakong.nbc.gov.kh/v1/generate_deeplink_by_qr',
                '00020101021229190015john_smith@devb5204599953038405405100.05802KH5910John Smith6010Phnom Penh',
            ],
            'errorCode' => null,
        ];
        self::test_deep_link($data);
    }
}
