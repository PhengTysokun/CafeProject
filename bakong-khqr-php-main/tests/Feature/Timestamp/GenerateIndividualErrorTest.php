<?php

declare(strict_types=1);

namespace KHQR\Tests\Feature\Timestamp;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\KHQRData;
use KHQR\Models\IndividualInfo;
use PHPUnit\Framework\TestCase;

class GenerateIndividualErrorTest extends TestCase
{
    private array $testData = [
        [
            'statement' => 'Expiration timestamp is required for dynamic KHQR',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'johnsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'PHNOM PENH',
                ],
                'optional' => [
                    'currency' => KHQRData::CURRENCY_USD,
                    'amount' => 101.3,
                ],
            ],
            'result' => [
                'error' => KHQRException::EXPIRATION_TIMESTAMP_REQUIRED,
            ],
        ],
        [
            'statement' => 'Expiration timestamp is in the past',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'johnsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'PHNOM PENH',
                ],
                'optional' => [
                    'currency' => KHQRData::CURRENCY_USD,
                    'amount' => 101.3,
                    'expirationTimestamp' => '1727260807000',
                ],
            ],
            'result' => [
                'error' => KHQRException::EXPIRATION_TIMESTAMP_IN_THE_PAST,
            ],
        ],
        [
            'statement' => 'Expiration timestamp length is invalid',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'johnsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'PHNOM PENH',
                ],
                'optional' => [
                    'currency' => KHQRData::CURRENCY_USD,
                    'amount' => 101.3,
                    'expirationTimestamp' => '1727260807',
                ],
            ],
            'result' => [
                'error' => KHQRException::EXPIRATION_TIMESTAMP_LENGTH_INVALID,
            ],
        ],
    ];

    public function test_generate_individual_error()
    {
        foreach ($this->testData as $data) {
            $requiredData = $data['data']['required'];
            $optionalData = $data['data']['optional'];

            $individualInfo = IndividualInfo::withOptionalArray(
                $requiredData['bakongAccountID'],
                $requiredData['merchantName'],
                $requiredData['merchantCity'],
                $optionalData
            );

            try {
                $khqrData = BakongKHQR::generateIndividual($individualInfo);
            } catch (KHQRException $e) {
                $expectedErrorCode = KHQRException::getError($data['result']['error'])[0];
                $this->assertEquals($expectedErrorCode, $e->getCode(), $data['statement']);
            }
        }
    }
}
