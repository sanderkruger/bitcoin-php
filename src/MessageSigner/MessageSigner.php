<?php

declare(strict_types=1);

namespace BitWasp\Bitcoin\MessageSigner;

use BitWasp\Bitcoin\Address\Address;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Crypto\Random\Rfc6979;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Buffertools\Buffertools;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Exceptions\SignerException;

class MessageSigner
{
    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @param EcAdapterInterface $ecAdapter
     */
    public function __construct(EcAdapterInterface $ecAdapter = null)
    {
        $this->ecAdapter = $ecAdapter ?: Bitcoin::getEcAdapter();
    }

    /**
     * @param NetworkInterface $network
     * @param string $message
     * @return BufferInterface
     * @throws \Exception
     */
    private function calculateBody(NetworkInterface $network, string $message): BufferInterface
    {
        $prefix = sprintf("%s:\n", $network->getSignedMessageMagic());
        return new Buffer(sprintf(
            "%s%s%s%s",
            Buffertools::numToVarInt(strlen($prefix))->getBinary(),
            $prefix,
            Buffertools::numToVarInt(strlen($message))->getBinary(),
            $message
        ));
    }

    /**
     * @param NetworkInterface $network
     * @param string $message
     * @return BufferInterface
     */
    public function calculateMessageHash(NetworkInterface $network, string $message): BufferInterface
    {
        return Hash::sha256d($this->calculateBody($network, $message));
    }

    /**
     * @param SignedMessage $signedMessage
     * @param Address $address
     * @param NetworkInterface|null $network
     * @return bool
     * @throws SignerException
     */
    public function verify(SignedMessage $signedMessage, Address $address, NetworkInterface $network = null): bool
    {
        if ($address instanceof SegwitAddress) {
            $version = $address->getWitnessProgram()->getVersion();
            if ($version > 0) {
                throw new SignerException('Wrong segwit address version');
            }
        } elseif (!$address instanceof PayToPubKeyHashAddress) {
            throw new SignerException('Wrong address format');
        }
        $network = $network ?: Bitcoin::getNetwork();
        $hash = $this->calculateMessageHash($network, $signedMessage->getMessage());

        $publicKey = $this->ecAdapter->recover(
            $hash,
            $signedMessage->getCompactSignature()
        );

        return $publicKey->getPubKeyHash()->equals($address->getHash());
    }

    /**
     * @param string $message
     * @param PrivateKeyInterface $privateKey
     * @param NetworkInterface|null $network
     * @return SignedMessage
     */
    public function sign(string $message, PrivateKeyInterface $privateKey, NetworkInterface $network = null): SignedMessage
    {
        $network = $network ?: Bitcoin::getNetwork();
        $hash = $this->calculateMessageHash($network, $message);

        return new SignedMessage(
            $message,
            $privateKey->signCompact(
                $hash,
                new Rfc6979(
                    $this->ecAdapter,
                    $privateKey,
                    $hash,
                    'sha256'
                )
            )
        );
    }
}
