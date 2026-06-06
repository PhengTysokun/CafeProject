<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use KHQR\Models\IndividualInfo;
use PHPUnit\Framework\TestCase;

class GenerateIndividualErrorTest extends TestCase
{
    private $testData = [
        [
            'statement' => 'Bakong account ID length error',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'johnsmith00123456789012345678912345@devb',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'PHNOM PENH',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => '012345678901234567890123456789',
                    'merchantCity' => 'Siam Reap',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reappppppppppppppppppppppppppp',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
            'statement' => 'Acquiring bank length invalid',
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
                    'acquiringBank' => 'Advanced Bank of Asia Limited Cambodia',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                ],
            ],
            'result' => [
                'error' => KHQRException::ACQUIRING_BANK_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Account Information invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 116,
                    'amount' => 50000,
                    'accountInformation' => '012345678901234567890123456789897',
                    'mobileNumber' => '85512345678',
                    'billNumber' => '1234',
                    'storeLabel' => 'BKK-1',
                    'terminalLabel' => '012345',
                    'acquiringBank' => 'Dev Bank',
                ],
            ],
            'result' => [
                'error' => KHQRException::ACCOUNT_INFORMATION_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Amount 1 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
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
            'statement' => 'Amount 6 invalid length',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 840,
                    'amount' => 12345678901234,
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
        [
            'statement' => 'Invalid UPI',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 116,
                    'upiMerchantAccount' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Duis a ultrices nunc. Quisque in sem fringilla, ullamcorper est vel',
                ],
            ],
            'result' => [
                'error' => KHQRException::UPI_ACCOUNT_INFORMATION_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Invalid alternative language',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 116,
                    'languagePreference' => 'Khmer',
                    'merchantNameAlternateLanguage' => 'ចន ស្មីន',
                ],
            ],
            'result' => [
                'error' => KHQRException::LANGUAGE_PREFERENCE_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Invalid alternative merchant name',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 116,
                    'languagePreference' => 'km',
                    'merchantNameAlternateLanguage' => 'Lorem ipsum dolor sit amet',
                ],
            ],
            'result' => [
                'error' => KHQRException::MERCHANT_NAME_ALTERNATE_LANGUAGE_LENGTH_INVALID,
            ],
        ],
        [
            'statement' => 'Invalid city name alter',
            'data' => [
                'required' => [
                    'bakongAccountID' => 'jonhsmith@nbcq',
                    'merchantName' => 'Jonh Smith',
                    'merchantCity' => 'Siam Reap',
                ],
                'optional' => [
                    'currency' => 116,
                    'languagePreference' => 'km',
                    'merchantNameAlternateLanguage' => 'Lorem ipsum',
                    'merchantCityAlternateLanguage' => 'Lorem ipsum dolor',
                ],
            ],
            'result' => [
                'error' => KHQRException::MERCHANT_CITY_ALTERNATE_LANGUAGE_LENGTH_INVALID,
            ],
        ],
    ];

    public function test_generate_individual_error()
    {
        foreach ($this->testData as $data) {
            $requiredData = $data['data']['required'];
            $optionalData = $data['data']['optional'];

            try {
                $individualInfo = IndividualInfo::withOptionalArray(
                    $requiredData['bakongAccountID'],
                    $requiredData['merchantName'],
                    $requiredData['merchantCity'],
                    $optionalData
                );

                BakongKHQR::generateIndividual($individualInfo);
            } catch (KHQRException $e) {
                $expectedErrorCode = KHQRException::getError($data['result']['error'])[0];
                $this->assertEquals($expectedErrorCode, $e->getCode(), $data['statement']);
            }
        }
    }
}
