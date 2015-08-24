<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace SpomkyLabs\Jose;

use Base64Url\Base64Url;
use Jose\JSONSerializationModes;
use Jose\JWAManagerInterface;
use Jose\JWKInterface;
use Jose\JWKManagerInterface;
use Jose\JWKSetInterface;
use Jose\JWKSetManagerInterface;
use Jose\JWTInterface;
use Jose\JWTManagerInterface;
use Jose\Operation\SignatureInterface;
use Jose\SignatureInstructionInterface;
use Jose\SignerInterface;
use SpomkyLabs\Jose\Payload\PayloadConverterManagerInterface;
use SpomkyLabs\Jose\Util\Converter;

/**
 */
class Signer implements SignerInterface
{
    use KeyChecker;

    /**
     * @var \SpomkyLabs\Jose\Payload\PayloadConverterManagerInterface
     */
    private $payload_converter;

    /**
     * @var \Jose\JWTManagerInterface
     */
    private $jwt_manager;

    /**
     * @var \Jose\JWKManagerInterface
     */
    private $jwk_manager;

    /**
     * @var \Jose\JWKSetManagerInterface
     */
    private $jwkset_manager;

    /**
     * @var \Jose\JWAManagerInterface
     */
    private $jwa_manager;

    /**
     * @param \SpomkyLabs\Jose\Payload\PayloadConverterManagerInterface $payload_converter
     *
     * @return self
     */
    public function setPayloadConverter(PayloadConverterManagerInterface $payload_converter)
    {
        $this->payload_converter = $payload_converter;

        return $this;
    }

    /**
     * @return \SpomkyLabs\Jose\Payload\PayloadConverterManagerInterface
     */
    public function getPayloadConverter()
    {
        return $this->payload_converter;
    }

    /**
     * @param \Jose\JWTManagerInterface $jwt_manager
     *
     * @return self
     */
    public function setJWTManager(JWTManagerInterface $jwt_manager)
    {
        $this->jwt_manager = $jwt_manager;

        return $this;
    }

    /**
     * @return \Jose\JWTManagerInterface
     */
    public function getJWTManager()
    {
        return $this->jwt_manager;
    }

    /**
     * @param \Jose\JWKManagerInterface $jwk_manager
     *
     * @return self
     */
    public function setJWKManager(JWKManagerInterface $jwk_manager)
    {
        $this->jwk_manager = $jwk_manager;

        return $this;
    }

    /**
     * @return \Jose\JWKManagerInterface
     */
    public function getJWKManager()
    {
        return $this->jwk_manager;
    }

    /**
     * @param \Jose\JWKSetManagerInterface $jwkset_manager
     *
     * @return self
     */
    public function setJWKSetManager(JWKSetManagerInterface $jwkset_manager)
    {
        $this->jwkset_manager = $jwkset_manager;

        return $this;
    }

    /**
     * @return \Jose\JWKSetManagerInterface
     */
    public function getJWKSetManager()
    {
        return $this->jwkset_manager;
    }

    /**
     * @param \Jose\JWAManagerInterface $jwa_manager
     *
     * @return self
     */
    public function setJWAManager(JWAManagerInterface $jwa_manager)
    {
        $this->jwa_manager = $jwa_manager;

        return $this;
    }

    /**
     * @return \Jose\JWAManagerInterface
     */
    public function getJWAManager()
    {
        return $this->jwa_manager;
    }
    /**
     * @param $input
     */
    private function checkInput(&$input)
    {
        if ($input instanceof JWTInterface) {
            return;
        }

        $header = [];
        $payload = $this->getPayloadConverter()->convertPayloadToString($header, $input);

        $jwt = $this->getJWTManager()->createJWT();
        $jwt->setPayload($payload)
            ->setProtectedHeader($header);
        $input = $jwt;
    }

    /**
     * @param array|JWKInterface|JWKSetInterface|JWTInterface|string $input         The input to sign
     * @param array                                                  $instructions  Signature instructions
     * @param string                                                 $serialization Serialization Overview
     *
     * @return string
     */
    public function sign($input, array $instructions, $serialization = JSONSerializationModes::JSON_COMPACT_SERIALIZATION)
    {
        $this->checkInput($input);
        $this->checkInstructions($instructions, $serialization);

        $jwt_payload = Base64Url::encode($input->getPayload());

        $signatures = [
            'payload'    => $jwt_payload,
            'signatures' => [],
        ];

        foreach ($instructions as $instruction) {
            $signatures['signatures'][] = $this->computeSignature($instruction, $input, $jwt_payload);
        }

        $prepared = Converter::convert($signatures, $serialization);

        return is_array($prepared) ? current($prepared) : $prepared;
    }

    /**
     * @param \Jose\SignatureInstructionInterface $instruction
     * @param \Jose\JWTInterface                  $input
     * @param string                              $jwt_payload
     *
     * @return array
     */
    protected function computeSignature(SignatureInstructionInterface $instruction, JWTInterface $input, $jwt_payload)
    {
        $protected_header = array_merge($input->getProtectedHeader(), $instruction->getProtectedHeader());
        $unprotected_header = array_merge($input->getUnprotectedHeader(), $instruction->getUnprotectedHeader());
        $complete_header = array_merge($protected_header, $protected_header);

        $jwt_protected_header = empty($protected_header) ? null : Base64Url::encode(json_encode($protected_header));

        $signature_algorithm = $this->getSignatureAlgorithm($complete_header, $instruction->getKey());

        if (!$this->checkKeyUsage($instruction->getKey(), 'signature')) {
            throw new \InvalidArgumentException('Key cannot be used to sign');
        }

        $signature = $signature_algorithm->sign($instruction->getKey(), $jwt_protected_header.'.'.$jwt_payload);

        $jwt_signature = Base64Url::encode($signature);

        $result = [
            'signature' => $jwt_signature,
        ];
        if (!is_null($protected_header)) {
            $result['protected'] = $jwt_protected_header;
        }
        if (!empty($unprotected_header)) {
            $result['header'] = $unprotected_header;
        }

        return $result;
    }

    /**
     * @param array              $complete_header The complete header
     * @param \Jose\JWKInterface $key
     *
     * @return \Jose\Operation\SignatureInterface
     */
    protected function getSignatureAlgorithm(array $complete_header, JWKInterface $key)
    {
        if (!array_key_exists('alg', $complete_header)) {
            if (is_null($key->getAlgorithm())) {
                throw new \InvalidArgumentException("No 'alg' parameter set in the header or the key.");
            } else {
                $alg = $key->getAlgorithm();
            }
        } else {
            $alg = $complete_header['alg'];
        }
        if (!is_null($key->getAlgorithm()) && $key->getAlgorithm() !== $alg) {
            throw new \InvalidArgumentException("The algorithm '$alg' is allowed with this key.");
        }

        $signature_algorithm = $this->getJWAManager()->getAlgorithm($alg);
        if (!$signature_algorithm instanceof SignatureInterface) {
            throw new \InvalidArgumentException("The algorithm '$alg' is not supported.");
        }

        return $signature_algorithm;
    }

    /**
     * @param \Jose\EncryptionInstructionInterface[] $instructions
     * @param string                                 $serialization
     */
    protected function checkInstructions(array $instructions, $serialization)
    {
        if (empty($instructions)) {
            throw new \InvalidArgumentException('No instruction.');
        }
        if (count($instructions) > 1 && JSONSerializationModes::JSON_SERIALIZATION !== $serialization) {
            throw new \InvalidArgumentException('Only one instruction authorized when Compact or Flattened Serialization Overview is selected.');
        }
        foreach ($instructions as $instruction) {
            if (!$instruction instanceof SignatureInstructionInterface) {
                throw new \InvalidArgumentException('Bad instruction. Must implement SignatureInstructionInterface.');
            }
        }
    }
}
