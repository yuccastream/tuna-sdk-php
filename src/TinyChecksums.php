<?php

declare(strict_types=1);

namespace Tuna;

/**
 * The pinned tuna-tiny release and SHA-256 checksums of its binaries. Generated, do not edit.
 */
final class TinyChecksums
{
    public const VERSION = '0.2.0';

    /** @var array<string, string> */
    public const SHA256 = [
        'linux_amd64' => '1e0a95f069b6ca06f5294edaaa80a7e5f816830e44cd04a56961bcd48c85d5c8',
        'linux_arm64' => '661664619c28b4dbf3fdc164aa3bb2572d268d20fdba68cd9c7d330c6c89d382',
        'darwin_amd64' => 'fca7874dc1ba9298b488a8678420b417f3c3af1bd0a64c9bf130f5f3a8fe184d',
        'darwin_arm64' => 'e87a3279192c563b0d7db1211baec6434bd0fe00603594640edf33f9100f7972',
        'windows_amd64' => 'd8111e186b43c2cc779c5bba9ad880ec8235526f4756609873f08434690194d8',
        'windows_arm64' => '907de68d13fd83878ea6bc845df9e9250d977250c5f2c18c0fc68eabbce1a556',
        'freebsd_amd64' => 'd1f10da404cd110f79fd4001bbeeb9fa2ffa208d108da30236f4ed83adcf101e',
        'freebsd_arm64' => 'aece2a3da1e7c10e6036cbc07d80529c3fb8cea82ec42ea14cf0859dc4db4479',
        'openbsd_amd64' => 'e682dca2802909c70ece64c51d4b31bd084115be12b7358a00ef6114ce423bf7',
        'openbsd_arm64' => '39386e6ede8b3730cd1f4c304ca0e8edb7de2d33b11ad17eb1e02314e464b791',
    ];
}
