<?php
/** @noinspection PhpComposerExtensionStubsInspection */

require_once(__DIR__ . '/../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use TorneLIB\Helpers\Version;
use TorneLIB\Module\Config\WrapperConfig;
use TorneLIB\Module\Network\Wrappers\CurlWrapper;

try {
    Version::getRequiredVersion();
} catch (Exception $e) {
    die($e->getMessage());
}

/**
 * Class curlWrapperTest
 */
class tlsSuiteTest extends TestCase
{
    /**
     * @test
     * Make a TLS 1.3 request (if available).
     * @noinspection NotOptimalIfConditionsInspection
     */
    public function basicGetTLS13()
    {
        // version_compare(PHP_VERSION, '5.6', '>=')
        if (defined('CURL_SSLVERSION_TLSv1_3') && PHP_VERSION_ID >= 50600) {
            try {
                /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
                $tlsResponse = (new CurlWrapper())->
                setConfig(
                    (new WrapperConfig())
                        ->setOption(CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_3)
                        ->setUserAgent(sprintf('netcurl-%s', NETCURL_VERSION))
                )
                    ->request(
                        sprintf(
                            'https://ipv4.netcurl.org/?func=%s',
                            __FUNCTION__
                        )
                    )->getParsed();

                if (isset($tlsResponse->ip)) {
                    static::assertTrue(
                        filter_var($tlsResponse->ip, FILTER_VALIDATE_IP) &&
                        $tlsResponse->SSL->SSL_PROTOCOL === 'TLSv1.3'
                    );
                }
            } catch (Exception $e) {
                // Getting connect errors here may indicate that the netcurl server is missing TLS 1.3 support.
                // TLS 1.3 is supported from Apache 2.4.37
                // Also be aware of the fact that not all PHP releases support it.
                if ($e->getCode() === CURLE_SSL_CONNECT_ERROR) {
                    // 14094410
                    static::markTestSkipped($e->getMessage());
                }
            }
        } elseif (PHP_VERSION_ID >= 50600) {
            static::markTestSkipped('TLSv1.3 problems: Your platform is too old to even bother.');
        } else {
            static::markTestSkipped('TLSv1.3 is not available on this platform.');
        }
    }
}
