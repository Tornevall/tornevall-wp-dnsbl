<?php

use PHPUnit\Framework\TestCase;
use TorneLIB\Exception\Constants;
use TorneLIB\Flags;
use TorneLIB\Helpers\Version;
use TorneLIB\Module\Config\WrapperSSL;

require_once(__DIR__ . '/../vendor/autoload.php');

try {
    Version::getRequiredVersion();
} catch (Exception $e) {
    die($e->getMessage());
}

/**
 * Class sslWrapperTest
 * Tests for the sslWrapper that is included via ConfigWrapper
 */
class sslWrapperTest extends TestCase
{
    /**
     * @test
     * @throws Exception
     */
    public function noSslWrappers()
    {
        /** @noinspection PhpUndefinedMethodInspection */
        Flags::_setFlag('NETCURL_NOSSL_TEST');
        try {
            (new WrapperSSL())->getSslCapabilities();
        } catch (Exception $e) {
            static::assertSame($e->getCode(), Constants::LIB_SSL_UNAVAILABLE);
        }

        /** @noinspection PhpUndefinedMethodInspection */
        Flags::_clearAllFlags(); // Clean up global flags.
    }

    /**
     * @test
     * @throws Exception
     */
    public function sslWrappers()
    {
        static::assertTrue((new WrapperSSL())->getSslCapabilities());
    }

    /**
     * @test
     * @throws Exception
     */
    public function strictValidation()
    {
        $sslAction = new WrapperSSL();
        $sslAction->setStrictVerification(false);
        $verifyPeerChange = $sslAction->getSecurityLevelChanges();

        /*
         * Tests includes:
         *  - Disable verify_peer (assert false).
         *  - Check the default content of allow_self_signed (assert).
         *  - Set own context and validates content.
         *  - Set own context and validates content by keyed call to getContext()
         *  - Verify that security level changes are "logged".
         */
        static::assertTrue(
            !(bool)$sslAction->getContext()['verify_peer'] &&
            !(new WrapperSSL())->getContext()['allow_self_signed'] &&
            (new WrapperSSL())->setContext('passphrase', 'simple_phrase')->getContext()['passphrase'] &&
            (new WrapperSSL())->setContext(
                'passphrase',
                'simple_phrase'
            )->getContext('passphrase') === 'simple_phrase' &&
            is_array($verifyPeerChange) &&
            count($verifyPeerChange) === 1
        );
    }

    /**
     * @test
     * @testdox Make sure the streamcontext are created properly.
     */
    public function getPreparedSslContext()
    {
        /** @noinspection PhpUnitTestsInspection */
        static::assertTrue(is_resource((new WrapperSSL())->getSslStreamContext()['stream_context']));
    }
}
