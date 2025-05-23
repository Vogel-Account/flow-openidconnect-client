<?php
namespace Flownative\OpenIdConnect\Client;

use JsonException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token;
use Neos\Utility\Arrays;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

/**
 * Value object for an OpenID Connect identity token
 *
 * @see https://openid.net/specs/openid-connect-basic-1_0.html#IDToken
 */
class IdentityToken
{
    public array $values = [];
    private array $header;
    private string $jwt;
    private Token $parsedJwt;
    private string $payload;
    private string $signature;

    /**
     * @see https://tools.ietf.org/html/rfc7519
     */
    public static function fromJwt(string $jwt): IdentityToken
    {
        $identityToken = new static();
        $identityToken->jwt = $jwt;

        if (preg_match('/^[a-zA-Z0-9=_-]+\.([a-zA-Z0-9=_-]+\.)+[a-zA-Z0-9=_-]+$/', $jwt) !== 1) {
            throw new \InvalidArgumentException('The given string was not a valid encoded identity token.', 1559204596);
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('The given JWT does not have exactly 3 parts (header, payload, signature), which is currently not supported by this implementation.', 1559208004);
        }

        // The JSON Web Signature (JWS), see https://tools.ietf.org/html/rfc7515
        $identityToken->signature = self::base64UrlDecode(array_pop($parts));
        if (empty($identityToken->signature)) {
            throw new \InvalidArgumentException('Failed decoding signature from JWT.', 1559207444);
        }

        // The JOSE Header (JSON Object Signing and Encryption), see: https://tools.ietf.org/html/rfc7515
        try {
            $header = json_decode(self::base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($header)) {
                throw new \InvalidArgumentException('Failed decoding JOSE header from JWT.', 1559207497);
            }
            $identityToken->header = $header;
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Failed decoding JOSE header from JWT.', 1603362934, $e);
        }
        if (!isset($identityToken->header['alg'])) {
            throw new \InvalidArgumentException('Missing signature algorithm in JOSE header from JWT.', 1559212231);
        }

        // The JWT payload, including header, sans signature
        $identityToken->payload = implode('.', $parts);

        try {
            $identityTokenArray = json_decode(self::base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Failed decoding identity token from JWT.', 1603362918, $e);
        }
        if (!is_array($identityTokenArray)) {
            throw new \InvalidArgumentException('Failed decoding identity token from JWT.', 1559208043);
        }

        $jwtParser = new Token\Parser(new JoseEncoder());

        $identityToken->values = $identityTokenArray;
        $identityToken->parsedJwt = $jwtParser->parse($jwt);
        return $identityToken;
    }

    public function asJwt(): string
    {
        return $this->jwt;
    }

    /**
     * Verify the signature (JWS) of this token using a given JWK
     *
     * @param array $jwks The JSON Web Keys to use for verification
     * @throws ServiceException
     * @see https://tools.ietf.org/html/rfc7517
     */
    public function hasValidSignature(array $jwks): bool
    {
        switch ($this->header['alg']) {
            case 'RS256':
            case 'RS384':
            case 'RS512':
                $hashType = 'sha' . substr($this->header['alg'], 2);
                $isValid = $this->verifyRsaJwtSignature(
                    $hashType,
                    $this->getMatchingKeyForJws($jwks, $this->header['alg'], $this->header['kid'] ?? null),
                    $this->payload,
                    $this->signature
                );
                break;
            default:
                throw new ServiceException(sprintf('Unsupported JWT signature type %s.', $this->header['alg']), 1559213623);
        }
        return $isValid;
    }

    public function isExpiredAt(\DateTimeInterface $now): bool
    {
        return $this->parsedJwt->isExpired($now);
    }

    /**
     * Checks if the identity token's "scope" value contains the given identifier
     */
    public function scopeContains(string $scopeIdentifier): bool
    {
        $scopeIdentifiers = Arrays::trimExplode(',', $this->values['scope'] ?? '');
        return in_array($scopeIdentifier, $scopeIdentifiers, true);
    }

    /**
     * Verifies a signature for the given payload using the given JSON web key and hash type.
     *
     * @param string $hashType The used hash type, for example SHA256, SHA384 or SHA512
     * @param array $jwk The JSON Web Key as an array, with array keys like "kid", "kty", "use", "alg", "exp" etc.
     * @param string $payload The JWT payload, including header
     * @param string $signature The JWS
     */
    private function verifyRsaJwtSignature(string $hashType, array $jwk, string $payload, string $signature): bool
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            throw new \InvalidArgumentException('Failed verifying RSA JWT signature because of an invalid JSON Web Key.', 1559214667);
        }
        $key = PublicKeyLoader::load([
            'e' => new BigInteger(self::base64UrlDecode($jwk['e']), 256),
            'n' => new BigInteger(self::base64UrlDecode($jwk['n']), 256)
        ])
            ->withHash($hashType)
            ->withPadding(RSA::SIGNATURE_PKCS1);
        return $key->verify($payload, $signature);
    }

    /**
     * Returns the matching JWK from the given list of keys, according to the specified algorithm and optional key identifier
     *
     * @throws ServiceException
     */
    private function getMatchingKeyForJws(array $keys, string $algorithm, ?string $keyIdentifier): array
    {
        foreach ($keys as $key) {
            if ($key['kty'] === 'RSA') {
                if ($keyIdentifier === null || !isset($key['kid']) || $key['kid'] === $keyIdentifier) {
                    return $key;
                }
            } elseif (isset($key['alg']) && $key['alg'] === $algorithm && $key['kid'] === $keyIdentifier) {
                return $key;
            }
        }
        if (!empty($keyIdentifier)) {
            throw new ServiceException(sprintf('Failed finding a matching JSON Web Key using algorithm %s for key identifier %s.', $algorithm, $keyIdentifier), 1559213482);
        }
        throw new ServiceException(sprintf('Failed finding a matching JSON Web Key using RSA for key identifier %s.', $keyIdentifier), 1559213507);
    }

    /**
     * Decode Base64URL-encoded data
     */
    private static function base64UrlDecode(string $base64UrlEncodedString): string
    {
        $padding = strlen($base64UrlEncodedString) % 4;
        if ($padding > 0) {
            $base64UrlEncodedString .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($base64UrlEncodedString, '-_', '+/'));
    }

    public function __toString(): string
    {
        return $this->asJwt();
    }
}
