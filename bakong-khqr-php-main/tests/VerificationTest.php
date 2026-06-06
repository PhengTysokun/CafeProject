<?php

declare(strict_types=1);

namespace KHQR\Tests;

use KHQR\BakongKHQR;
use KHQR\Helpers\EMV;
use KHQR\Helpers\Utils;
use KHQR\Models\Timestamp;
use KHQR\Models\TimestampData;
use PHPUnit\Framework\TestCase;

class VerificationTest extends TestCase
{
    private $testData = [
        [
            'statement' => 'Valid KHQR 1',
            'data' => '00020101021229180014jonhsmith@nbcq520459995303116540750000.05802KH5910Jonh Smith6010Phnom Penh6215021185512345678',
            'result' => true,
        ],
        [
            'statement' => 'Valid KHQR 2',
            'data' => '00020101021229180014jonhsmith@nbcq52045999530384054031.05802KH5910Jonh Smith6010PHNOM PENH62210117INV-2021-07-65822',
            'result' => true,
        ],
        [
            'statement' => 'Valid KHQR 3',
            'data' => '00020101021230400014jonhsmith@devb01061234560208Dev Bank520459995303840540410.05802KH5910Jonh Smith6010Phnom Penh62360117INV-2021-07-65822021185512345678',
            'result' => true,
        ],
        [
            'statement' => 'Invalid KHQR 4',
            'data' => '00020101021230400014jonhsmith@devb01061234560208Dev Bank520459995303840540410.05802KH5910Jonh Smith6010Phnom Penh62360117INV-2021-07-65822021185512345678',
            'result' => true,
        ],
        [
            'statement' => 'Invalid KHQR 5',
            'data' => '9917001316257134678276250070201030412340211855854989940117INV-2021-07-658226010PHNOM PENH5910Jonh Smith5802KH540115303840520459992926070412340014jonhsmith@nbcq0102120002016304966B',
            'result' => true,
        ],
        [
            'statement' => 'Invalid KHQR 6',
            'data' => '00020101021230400014jonhsmith@devb01061234560208Dev Bank520459995303840540410.05802KH5910Jonh Smith6010Phnom Penh62360117INV-2021-07-65822021185512345678',
            'result' => true,
        ],
        [
            'statement' => 'Invalid KHQR 2',
            'data' => '00020101021126200016coffeeklang@pras',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 3',
            'data' => '63046007',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 4',
            'data' => 'null',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 5',
            'data' => '\'\'',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 6',
            'data' => '                   ',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 7',
            'data' => 'ABC',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 8',
            'data' => 'ABC     ',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 9',
            'data' => 'កខគឃង',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 10',
            'data' => '00020101021126150011jonhsmith@nbcq5204599953031165802KH5912Jonh Smith6010Phnom Penh',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 11',
            'data' => '00020101021126150011jonhsmith@nbcq5204599953031165802KH5912Jonh Smith6010Phnom Penh',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 12',
            'data' => 'EAB3',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 13',
            'data' => '00020101021230190015',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 14',
            'data' => '10263041234',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 15',
            'data' => '63041234',
            'result' => false,
        ],
        [
            'statement' => 'Valid KHQR 16',
            'data' => '00020101021230410015john_smith@devb01061234560208Dev Bank53038405204599954035.05802KH5916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => true,
        ],
        [
            'statement' => 'Invalid KHQR 17',
            'data' => '00020101021252045999530384054035.05802KH5916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 18',
            'data' => '00020101021230190015123456789012345',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 19',
            'data' => '000201010212304400401234567890123456789012345678901234567890',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 20',
            'data' => '000301001021230190015john_smith@devb',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 21',
            'data' => '01021230190015john_smith@devb52045999530384054035.05802KH5916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 22',
            'data' => '000201010312330190015john_smith@devb',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 23',
            'data' => '00020101021229190015john_smith@devb5203999',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 24',
            'data' => '00020101021229190015john_smith@devb530384054035.05802KH5916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 25',
            'data' => '00020101021229190015john_smith@devb5204599953048840',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 26',
            'data' => '00020101021229190015john_smith@devb5204599954035.05802KH5916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 27',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05803KKH',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 28',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05916john smith actor6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 29',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH5916john smith actor601701234567890123456',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 30',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH5916john smith actor600062150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 31',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH5916john smith actor62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 32',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH59307PL7EvxHpgpP4jT4uMgegaYqgv3Ehb6010Phnom Penh',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 33',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH59006010Phnom Penh',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 34',
            'data' => '00020101021229190015john_smith@devb52045999530384054035.05802KH6010Phnom Penh62150111Invoice#069',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 35',
            'data' => '00020101021229190015john_smith@devb52045999530311654065000.05802KH5910jonh smith6010Phnom Penh622701000313Coffee Klaing0702#2',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 36',
            'data' => '00020101021229190015john_smith@devb52045999530311654065000.05802KH5910jonh smith6010Phnom Penh62230109#INV-200303000702#2',
            'result' => false,
        ],
        [
            'statement' => 'Invalid KHQR 37',
            'data' => '00020101021229190015john_smith@devb52045999530311654065000.05802KH5910jonh smith6010Phnom Penh62340109#INV-20030313Coffee Klaing0700',
            'result' => false,
        ],
        [
            'statement' => 'Valid KHQR 38',
            'data' => '00020101021229190015john_smith@devb52045999530384954035.05802KH5916john smith actor6010Phnom Penh',
            'result' => false,
        ],
    ];

    private $testDataFromFailedDecode = [
        [
            'statement' => 'KHQR provided is invalid',
            'data' => '000201',
            'errorCode' => 8,
        ],
        [
            'statement' => 'Payload Format Indicator cannot be null or empty',
            'data' => '020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 23,
        ],
        [
            'statement' => 'Payload Format Indicator Length is invalid',
            'data' => '000300101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 16,
        ],
        [
            'statement' => 'Point of Initiation Length is invalid',
            'data' => '000201010301230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 17,
        ],
        [
            'statement' => 'Merchant type cannot be null or empty',
            'data' => '00020101021252045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 5,
        ],
        [
            'statement' => 'Bakong Account ID length is invalid',
            'data' => '00020101021230390035john_smmmmmmmmmmmithhhhhhhhhhh@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 6,
        ],
        [
            'statement' => 'Bakong Account ID is invalid',
            'data' => '00020101021230190018john_smith_devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 3,
        ],
        [
            'statement' => 'Merchant Category cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 25,
        ],
        [
            'statement' => 'Merchant Category Length is invalid',
            'data' => '00020101021230190015john_smith@devb520559999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 18,
        ],
        [
            'statement' => 'Currency type cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb5204599954035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 9,
        ],
        [
            'statement' => 'Transaction Currency Length is invalid',
            'data' => '00020101021230190015john_smith@devb520459995304840054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 19,
        ],
        [
            'statement' => 'Unsupported currency',
            'data' => '00020101021230190015john_smith@devb52045999530397854035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 28,
        ],
        [
            'statement' => 'Transaction amount length is invalid',
            'data' => '00020101021230190015john_smith@devb5204599953031165414500000000000.05802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 4,
        ],
        [
            'statement' => 'Country Code cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 26,
        ],
        [
            'statement' => 'Country Code Length is invalid',
            'data' => '00020101021230180014johnsmith@devb52045999530384054035005807VIETNAM5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 20,
        ],
        [
            'statement' => 'Merchant name cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 2,
        ],
        [
            'statement' => 'Merchant name length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5929Joooooooooooooooooooohn Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 7,
        ],
        [
            'statement' => 'Merchant City cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 27,
        ],
        [
            'statement' => 'Merchant City Length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6016Phnommmmmmm Penh62460111Invoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 21,
        ],
        [
            'statement' => 'Bill number length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62610126Invvvvvvvvvvvvvvvvoice#0690314Coffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 10,
        ],
        [
            'statement' => 'Store label length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62590111Invoice#0690327Cooooooooooooooffee Khlaing0709Counter 29917001316140656838196304',
            'errorCode' => 11,
        ],
        [
            'statement' => 'Terminal label length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62640111Invoice#0690314Coffee Khlaing0727Cooooooooooooooooooounter 29917001316140656838196304',
            'errorCode' => 12,
        ],
        [
            'statement' => 'KHQR provided is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 2991700131614065683819',
            'errorCode' => 24,
        ],
        [
            'statement' => 'CRC cannot be null or empty',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 2991700131614065683819',
            'errorCode' => 24,
        ],
        [
            'statement' => 'CRC Length is invalid',
            'data' => '00020101021230190015john_smith@devb52045999530384054035005802KH5910John Smith6010Phnom Penh62460111Invoice#0690314Coffee Khlaing0709Counter 299170013161406568381963050F276',
            'errorCode' => 22,
        ],
    ];

    public function test_khqr_verification()
    {
        foreach ($this->testData as $data) {
            $currentTimestampInMilliseconds = floor(microtime(true) * 1000);
            $timestampData = new TimestampData(strval($currentTimestampInMilliseconds), strval($currentTimestampInMilliseconds + 60 * 1000));
            $timestamp = new Timestamp(EMV::TIMESTAMP_TAG, $timestampData, EMV::DYNAMIC_QR);
            $khqr = $data['data'].$timestamp.EMV::CRC.EMV::CRC_LENGTH;
            $khqr .= Utils::crc16($khqr);

            $crcValidation = BakongKHQR::verify($khqr);
            $this->assertEquals($data['result'], $crcValidation->isValid, $data['statement']);
        }
    }

    public function test_khqr_failed_decode()
    {
        foreach ($this->testDataFromFailedDecode as $data) {
            $khqr = $data['data'].Utils::crc16($data['data']);
            $crcValidation = BakongKHQR::verify($khqr);
            $this->assertEquals(false, $crcValidation->isValid, $data['statement']);
        }
    }
}
