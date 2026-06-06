<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use KHQR\Models\MerchantInfo;
use PHPUnit\Framework\TestCase;

class GenerateMerchantErrorTest extends TestCase
{
    private $testData = [
        [
            'statement' => 'Bakong account ID length error',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'johnsmith00123456789012345678912345@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 1,
                    'billNumber' => 'INV-2021-07-65822',
                ],
            ],
            'result' => [
                'error' => KHQRException::BAKONG_ACCOUNT_ID_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Bakong Account ID invalid error',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmithnbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                ],
            ],
            'result' => [
                'error' => KHQRException::BAKONG_ACCOUNT_ID_INVALID,
            ],
        ],
        [
            'statement' => 'Bakong Account ID not found or null',
            'data' => [
                'required' => [
                    'bakongAccountID' => '',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'amount' => 50000,
                    'storeLabel' => 'BKK-1',
                ],
            ],
            'result' => [
                'error' => KHQRException::BAKONG_ACCOUNT_ID_REQUIRED,
            ],
        ],
        [
            'statement' => 'Merchant name length error',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smithhhhhhhhhhhhhhhhhhhhhhhh',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => 'INV-2021-07-65822',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                ],
            ],
            'result' => [
                'error' => KHQRException::MERCHANT_NAME_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Transaction amount invalid error',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 123456789012345.67,
                    'acquiringBank' => 'Dev Bank',
                    'accountInformation' => '012345678',
                    'mobileNumber' => '85512345678',
                    'billNumber' => 'INV-2021-07-65822',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Merchant City length invalid',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reappppppppppppppppppppppppppp',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => 'INV-2021-07-65822',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::MERCHANT_CITY_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Billnumber invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '012345678901234567890123456789',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::BILL_NUMBER_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Mobile number invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678901234567890123456789',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::MOBILE_NUMBER_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Storelabel invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => '012345678901234567890123456789',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::STORE_LABEL_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Terminal invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Phnom Penh',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345678901234567890123456789',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TERMINAL_LABEL_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 1 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 1.234,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 2 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => -1000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 3 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 100.00111,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 4 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 100.0000000000111,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 5 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 1234567890123.01,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 6 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 999999999999.9999,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 7 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 1.1234567890123457,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 8 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => -1000,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 9 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 100.00111,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 10 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 100.0000000000111,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 11 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 999999999999.9999,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 12 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 1.1234567890123457,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 13 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                    'merchantId' => '123456',
                    'acquiringBank' => 'Dev Bank',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 1.1,
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::TRANSACTION_AMOUNT_INVALID,
            ],
        ],
    ];

    public function test_generate_merchant_error()
    {
        foreach ($this->testData as $data) {
            $requiredData = $data['data']['required'];
            $optionalData = $data['data']['optional'];

            try {
                $merchantInfo = MerchantInfo::withOptionalArray(
                    $requiredData['bakongAccountID'],
                    $requiredData['merchantName'],
                    $requiredData['merchantCity'],
                    $requiredData['merchantId'],
                    $requiredData['acquiringBank'],
                    $optionalData
                );

                BakongKHQR::generateMerchant($merchantInfo);
            } catch (KHQRException $e) {
                $expectedErrorCode = KHQRException::getError($data['result']['error'])[0];
                $this->assertEquals($expectedErrorCode, $e->getCode(), $data['statement']);
            }
        }
    }
}
