<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;
use PHPUnit\Framework\TestCase;

class GenerateIndividualTest extends TestCase
{
    private string $expirationTimestamp;

    protected function setUp(): void
    {
        $this->expirationTimestamp = strval(floor(microtime(true) * 1000) + 60 * 1000);
    }

    public function test_generate_individual_qr(): void
    {
        $testData = [
            [
                'statement' => 'Success Generate 1',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'PHNOM PENH',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 1,
                        'billNumber' => 'INV-2021-07-65822',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229180014jonhsmith@nbcq520459995303840540115802KH5910Jonh Smith6010PHNOM PENH62210117INV-2021-07-65822',
            ],
            [
                'statement' => 'Success Generate 2',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Phnom Penh',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'mobileNumber' => '85512345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229180014jonhsmith@nbcq5204599953031165405500005802KH5910Jonh Smith6010Phnom Penh6215021185512345678',
            ],
            [
                'statement' => 'Success Generate 3',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Phnom Penh',
                    ],
                    'optional' => [
                        'amount' => 50000,
                        'storeLabel' => 'BKK-1',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229180014jonhsmith@nbcq5204599953031165405500005802KH5910Jonh Smith6010Phnom Penh62090305BKK-1',
            ],
            [
                'statement' => 'Success Generate 4',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229180014jonhsmith@nbcq5204599953031165405500005802KH5910Jonh Smith6009Siam Reap62550117INV-2021-07-658220211855123456780305BKK-10706012345',
            ],
            [
                'statement' => 'Success Generate 5',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'acquiringBank' => 'Dev Bank',
                        'accountInformation' => '012345678',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229430014jonhsmith@nbcq01090123456780208Dev Bank5204599953031165405500005802KH5910Jonh Smith6009Siam Reap62550117INV-2021-07-658220211855123456780305BKK-10706012345',
            ],
            [
                'statement' => 'Success Generate 6',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'acquiringBank' => 'Dev Bank',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229300014jonhsmith@nbcq0208Dev Bank5204599953031165405500005802KH5910Jonh Smith6009Siam Reap62550117INV-2021-07-658220211855123456780305BKK-10706012345',
            ],
            [
                'statement' => 'Success Generate 7',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'acquiringBank' => 'Dev Bank',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229300014jonhsmith@nbcq0208Dev Bank5204599953031165405500005802KH5910Jonh Smith6009Siam Reap62550117INV-2021-07-658220211855123456780305BKK-10706012345',
            ],
            [
                'statement' => 'Success Generate 8',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 50000,
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '00020101021229310014jonhsmith@nbcq01090123456785204599953031165405500005802KH5910Jonh Smith6009Siam Reap62550117INV-2021-07-658220211855123456780305BKK-10706012345',
            ],
            [
                'statement' => 'Success Generate 9',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'languagePreference' => 'km',
                        'merchantNameAlternateLanguage' => 'ចន ស្មីន',
                        'merchantCityAlternateLanguage' => 'សៀមរាប',
                    ],
                ],
                'result' => '00020101021129180014jonhsmith@nbcq5204599953031165802KH5910Jonh Smith6009Siam Reap64280002km0108ចន ស្មីន0206សៀមរាប',
            ],
            [
                'statement' => 'Success Generate 10',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Phnom Penh',
                    ],
                    'optional' => [
                        'mobileNumber' => '85512345678',
                        'purposeOfTransaction' => 'Testing',
                    ],
                ],
                'result' => '00020101021129180014jonhsmith@nbcq5204599953031165802KH5910Jonh Smith6010Phnom Penh62260211855123456780807Testing',
            ],
        ];

        foreach ($testData as $data) {
            $requiredData = $data['data']['required'];
            $optionalData = $data['data']['optional'];
            $sliceIndex = isset($optionalData['amount']) ? -46 : -8;

            $individualInfo = IndividualInfo::withOptionalArray(
                $requiredData['bakongAccountID'],
                $requiredData['merchantName'],
                $requiredData['merchantCity'],
                $optionalData
            );

            $khqrData = BakongKHQR::generateIndividual($individualInfo);
            $this->assertEquals($data['result'], mb_substr($khqrData->data['qr'], 0, $sliceIndex, 'UTF-8'), $data['statement']);
        }
    }

    public function test_generate_individual_amount(): void
    {
        $amountTestData = [
            [
                'statement' => 'Amount test 1',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 100,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100',
            ],
            [
                'statement' => 'Amount test 2',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 100,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100',
            ],
            [
                'statement' => 'Amount test 3',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 100,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100',
            ],
            [
                'statement' => 'Amount test 4',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 9999999999,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '9999999999',
            ],
            [
                'statement' => 'Amount test 5',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 10000,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '10000',
            ],
            [
                'statement' => 'Amount test 6',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 1.12,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '1.12',
            ],
            [
                'statement' => 'Amount test 7',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 1000,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '1000',
            ],
            [
                'statement' => 'Amount test 8',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 100.11,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100.11',
            ],
            [
                'statement' => 'Amount test 9',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 100.12,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100.12',
            ],
            [
                'statement' => 'Amount test 10',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 12345678901,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '12345678901',
            ],
            [
                'statement' => 'Amount test 11',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'Jonh Smith',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 840,
                        'amount' => 9999999999.99,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '9999999999.99',
            ],
            [
                'statement' => 'Name in UTF-8',
                'data' => [
                    'required' => [
                        'bakongAccountID' => 'jonhsmith@nbcq',
                        'merchantName' => 'ចន ស្មីន',
                        'merchantCity' => 'Siam Reap',
                    ],
                    'optional' => [
                        'currency' => 116,
                        'amount' => 100,
                        'acquiringBank' => 'Dev Bank',
                        'mobileNumber' => '85512345678',
                        'billNumber' => 'INV-2021-07-65822',
                        'storeLabel' => 'BKK-1',
                        'terminalLabel' => '012345',
                        'accountInformation' => '012345678',
                        'expirationTimestamp' => $this->expirationTimestamp,
                    ],
                ],
                'result' => '100',
            ],
        ];

        foreach ($amountTestData as $data) {
            $requiredData = $data['data']['required'];
            $optionalData = $data['data']['optional'];

            $individualInfo = IndividualInfo::withOptionalArray(
                $requiredData['bakongAccountID'],
                $requiredData['merchantName'],
                $requiredData['merchantCity'],
                $optionalData
            );

            $khqrData = BakongKHQR::generateIndividual($individualInfo);
            $decodedKhqrData = BakongKHQR::decode($khqrData->data['qr']);
            $this->assertEquals($data['result'], $decodedKhqrData->data['transactionAmount'], $data['statement']);
        }
    }
}
