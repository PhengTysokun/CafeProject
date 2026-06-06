<?php

declare(strict_types=1);

namespace KHQR\Tests\Feature\NonKhqr;

use KHQR\BakongKHQR;
use PHPUnit\Framework\TestCase;

class DecodeTest extends TestCase
{
    /** @var array<mixed> */
    private array $testData = [
        [
            'statement' => 'Invalid crc length',
            'data' => '00020101021115231234567812345678901234530400014jonhsmith@nbcq01061234560208Dev Bank5204599953031165802KH5910Jonh Smith6010Phnom Penh9917001316873168216096310abcdef',
            'result' => [
                '15' => '12345678123456789012345',
                '30' => [
                    '00' => 'jonhsmith@nbcq',
                    '01' => '123456',
                    '02' => 'Dev Bank',
                ],
                '52' => '5999',
                '53' => '116',
                '58' => 'KH',
                '59' => 'Jonh Smith',
                '60' => 'Phnom Penh',
                '99' => [
                    '00' => '1687316821609',
                ],
                '00' => '01',
                '01' => '11',
            ],
        ],
        [
            'statement' => 'Invalid EMVQR',
            'data' => '002',
            'result' => [],
        ],
        [
            'statement' => 'Incorrect length of tag 52',
            'data' => '00020101021115231234567812345678901234530400014jonhsmith@nbcq01061234560208Dev Bank5206599953031165802KH5910Jonh Smith6010Phnom Penh9917001316873168216096304D82B',
            'result' => [
                '15' => '12345678123456789012345',
                '30' => [
                    '00' => 'jonhsmith@nbcq',
                    '01' => '123456',
                    '02' => 'Dev Bank',
                ],
                '52' => '599953',
                '00' => '01',
                '01' => '11',
                '03' => '65802KH5910',
            ],
        ],
        [
            'statement' => 'Decode Non-KHQR 1',
            'data' => '00020101021229190015john_smith@devb52045999530311654065000.05802KH5910jonh smith6010Phnom Penh62360109#INV-20030313Coffee Klaing0702#299170013161302797275763049ACF',
            'result' => [
                '00' => '01',
                '01' => '12',
                '29' => [
                    '00' => 'john_smith@devb',
                ],
                '52' => '5999',
                '53' => '116',
                '54' => '5000.0',
                '58' => 'KH',
                '59' => 'jonh smith',
                '60' => 'Phnom Penh',
                '62' => [
                    '01' => '#INV-2003',
                    '03' => 'Coffee Klaing',
                    '07' => '#2',
                ],
                '99' => [
                    '00' => '1613027972757',
                ],
                '63' => '9ACF',
            ],
        ],
        [
            'statement' => 'Decode Non-KHQR 2',
            'data' => '00020101021115140110555555555530400014jonhsmith@nbcq01061234560208Dev Bank5204599953031165802KH5910Jonh Smith6010Phnom Penh9924011316873168216090203TTT6304EC18',
            'result' => [
                '15' => '01105555555555',
                '30' => [
                    '00' => 'jonhsmith@nbcq',
                    '01' => '123456',
                    '02' => 'Dev Bank',
                ],
                '52' => '5999',
                '53' => '116',
                '58' => 'KH',
                '59' => 'Jonh Smith',
                '60' => 'Phnom Penh',
                '63' => 'EC18',
                '99' => [
                    '01' => '1687316821609',
                    '02' => 'TTT',
                ],
                '00' => '01',
                '01' => '11',
            ],
        ],
        [
            'statement' => 'Decode Non-KHQR 3',
            'data' => '00020101021129370009khqr@aclb011009686507250206ACLEDA392000118551121508001014520420005802KH53031165912Chhuon Vutha6010Phnom Penh62420210096865072510020111070103ABC99070103ABC65240003ABC01020199070103ABC99340013171740156077901020199070103ABC63046995',
            'result' => [
                '29' => [
                    '00' => 'khqr@aclb',
                    '01' => '0968650725',
                    '02' => 'ACLEDA',
                ],
                '39' => [
                    '00' => '85511215080',
                    '01' => '4',
                ],
                '52' => '2000',
                '53' => '116',
                '58' => 'KH',
                '59' => 'Chhuon Vutha',
                '60' => 'Phnom Penh',
                '62' => [
                    '10' => '01',
                    '11' => '0103ABC',
                    '99' => [
                        '01' => 'ABC',
                    ],
                    '02' => '0968650725',
                ],
                '63' => '6995',
                '65' => '0003ABC01020199070103ABC',
                '99' => [
                    '99' => '0103ABC',
                    '00' => '1717401560779',
                    '01' => '01',
                ],
                '00' => '01',
                '01' => '11',
            ],
        ],
    ];

    public function test_decode(): void
    {
        foreach ($this->testData as $data) {
            $decoded = BakongKHQR::decodeNonKhqr($data['data']);
            $this->assertEquals($data['result'], (array) $decoded->data, $data['statement']);
        }
    }
}
