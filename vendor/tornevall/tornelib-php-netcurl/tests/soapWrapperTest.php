<?php
/** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection PhpUndefinedMethodInspection */

require_once(__DIR__ . '/../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use TorneLIB\Config\Flag;
use TorneLIB\Exception\ExceptionHandler;
use TorneLIB\Helpers\Version;
use TorneLIB\Module\Config\WrapperConfig;
use TorneLIB\Module\Network\Wrappers\CurlWrapper;
use TorneLIB\Module\Network\Wrappers\SoapClientWrapper;
use TorneLIB\Utils\Security;

try {
    Version::getRequiredVersion('5.6');
} catch (Exception $e) {
    die($e->getMessage());
}

/** @noinspection PhpUnhandledExceptionInspection */
// Second argument transform check into true/false instead of exception.
if (!Security::getCurrentClassState('SoapClient', false)) {
    define('SKIP_SOAP', true);
}

/**
 * Class soapWrapperTest
 * Tests for all special SoapClient-requests.
 */
class soapWrapperTest extends TestCase
{
    private $rEcomPipeU = 'tornevall';
    private $rEcomPipeP = '2suyqJRXyd8YBGxTz42xr7g1tCWW6M2R';
    private $wsdl = 'https://test.resurs.com/ecommerce-test/ws/V4/SimplifiedShopFlowService?wsdl';
    private $wsdl_config = 'https://test.resurs.com/ecommerce-test/ws/V4/ConfigurationService?wsdl';
    private $no_wsdl = 'https://test.resurs.com/ecommerce-test/ws/V4/SimplifiedShopFlowService';
    private $netcurlWsdl = 'https://tests.netcurl.org/tornevall_network/index.wsdl?wsdl';

    /**
     * @return bool
     * @throws ExceptionHandler
     * @noinspection DuplicatedCode
     */
    private function canProxy()
    {
        $return = false;

        $ipList = [
            '212.63.208.',
            '10.1.1.',
        ];

        $wrapperData = (new CurlWrapper())
            ->setConfig((new WrapperConfig())->setUserAgent('ProxyTestAgent'))
            ->request('https://ipv4.netcurl.org')->getParsed();
        if (isset($wrapperData->ip)) {
            foreach ($ipList as $ip) {
                if ((bool)preg_match('/' . $ip . '/', $wrapperData->ip)) {
                    $return = true;
                    break;
                }
            }
        }

        return $return;
    }

    /**
     * @test
     * @noinspection PhpUnitTestsInspection
     */
    public function basicSoapClient()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        $soapWrapper = new SoapClientWrapper();
        // assertIsObject does not always exist
        static::assertTrue(is_object($soapWrapper));
    }

    /**
     * @test
     * @noinspection PhpUnitTestsInspection
     */
    public function getSoapUninitialized()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        $soapWrapper = (new SoapClientWrapper());
        // assertIsObject does not always exist
        static::assertTrue(is_object($soapWrapper));
    }

    /**
     * @test
     * @throws ExceptionHandler
     */
    public function getStreamContext()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        $soapWrapper = new SoapClientWrapper();
        $soapContext = $soapWrapper->getStreamContext();
        static::assertNotEmpty($soapContext['http']);
    }

    /**
     * @test
     * @throws ExceptionHandler
     */
    public function getSoapEmbeddedRandomRequest()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        // Note: We could've been chaining this one, but in this case, there's other stuff to test.
        $soapWrapper = (new SoapClientWrapper($this->netcurlWsdl));
        $soapWrapper->setAuthentication($this->rEcomPipeU, $this->rEcomPipeP);
        $soapWrapper->setUserAgent('That requesting client');
        $userAgent = $soapWrapper->getUserAgent();
        $soapContext = $soapWrapper->getStreamContext();
        $hasErrorOnRandom = false;

        try {
            $soapWrapper->getRandomRequest();
        } catch (Exception $e) {
            $hasErrorOnRandom = true;
        }
        static::assertTrue(
            !empty($soapContext['http']) &&
            $userAgent === 'That requesting client' &&
            $hasErrorOnRandom
        );
    }

    /**
     * @test
     * Test writing directly to stream_context instead of going through WrapperConfig. Also trying to use
     * overwritable flagset, as user_agent is normally internally protected from overwriting when going this way.
     * @throws ExceptionHandler
     */
    public function getSoapEmbeddedRandomRequestInstantStream()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        $directStreamUserAgent = 'stream_context_agent_request';
        $realDirectStreamUserAgent = 'stream_context_agent_request_ov';

        // Note: We could've been chaining this one, but in this case, there's other stuff to test.
        $soapWrapper = (new SoapClientWrapper($this->netcurlWsdl))
            ->setAuthentication($this->rEcomPipeU, $this->rEcomPipeP)
            ->setStreamContext('user_agent', $directStreamUserAgent, 'http');
        $hasErrorOnRandom = false;

        $soapWrapper->setStreamContext('user_agent', 'overwrited', 'http');
        Flag::setFlag('canoverwrite', ['user_agent']);
        $soapWrapper->setStreamContext('user_agent', $realDirectStreamUserAgent, 'http');

        $context = $soapWrapper->getStreamContext();
        $fromAgentContext = $soapWrapper->getUserAgent();

        try {
            $soapWrapper->getRandomRequest();
        } catch (Exception $e) {
            $hasErrorOnRandom = true;
        }
        static::assertTrue(
            !empty($context['http']) &&
            $context['http']['user_agent'] === $realDirectStreamUserAgent &&
            $fromAgentContext === $realDirectStreamUserAgent &&
            $hasErrorOnRandom
        );
    }

    /**
     * @test
     * Test to set proxy. The test is setting up a stream that does not exist. If this throws an exception, we know
     * that the stream context accepted the parameter.
     * @throws ExceptionHandler
     */
    public function getSoapEmbeddedRandomRequestProxy()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        // Note: We could've been chaining this one, but in this case, there's other stuff to test.
        $soapWrapper = (new SoapClientWrapper($this->netcurlWsdl))
            ->setAuthentication($this->rEcomPipeU, $this->rEcomPipeP)
            ->setStreamContext('proxy', 'http://null:80', 'http');

        try {
            $soapWrapper->getRandomRequest();
        } catch (Exception $e) {
            $message = $e->getMessage(); // expect: failed to open stream: Unable to find the socket transport
            static::assertTrue(
                $e->getCode() === 2 &&
                (bool)preg_match('/unable(.*?)transport/is', $message)
            );
        }
    }

    /**
     * @test
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function getSoapEmbeddedReal()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            $soapWrapper = (new SoapClientWrapper($this->wsdl))->setWsdlCache(WSDL_CACHE_DISK);
            $soapWrapper->setAuthentication($this->rEcomPipeU, $this->rEcomPipeP);
            $paymentMethods = $soapWrapper->getPaymentMethods();
            $functions = $soapWrapper->getFunctions();
            $lastRequest = $soapWrapper->getLastRequest();
            static::assertTrue(
                (
                    is_array($paymentMethods) &&
                    count($paymentMethods)
                ) &&
                is_array($functions) &&
                (
                    is_string($lastRequest) &&
                    $lastRequest !== ''
                )
            );
        } catch (Exception $e) {
            static::markTestSkipped(
                sprintf(
                    'Soaptest with expected success failed. API seems to be down (%s: %s).',
                    $e->getCode(),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * @test
     * Fail request with wrong credentials. Do cache wsdl on disk.
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function getSoapEmbeddedAuthFailCache()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            // NOTE: By setting cached wsdl here, authentication failures can not be cached.
            $soapWrapper = (new SoapClientWrapper($this->wsdl))->setWsdlCache(WSDL_CACHE_DISK);
            $soapWrapper->setAuthentication('fail', 'doubleFail')->getPaymentMethods();
        } catch (Exception $e) {
            /** @noinspection NotOptimalIfConditionsInspection */
            // Skip test if this is not the expected error as API could fail.
            if ($e->getCode() !== 401 && $e->getCode() !== 2) {
                static::markTestSkipped(
                    sprintf(
                        'Error %s (%s) unexpectedly from the called API. Skipping for now.',
                        $e->getCode(),
                        $e->getMessage()
                    )
                );
                return;
            }

            // Assert "Unauthorized" code (401) or error code 2 based on E_WARNING
            static::assertTrue(
                $e->getCode() === 401 ||
                $e->getCode() === 2
            );
        }
    }

    /**
     * @test
     * Fail request with wrong credentials. Do not cache wsdl on disk.
     */
    public function getSoapEmbeddedAuthFailUnCached()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            // NOTE: By setting cached wsdl here, authentication failures can not be cached.
            $soapWrapper = (new SoapClientWrapper($this->wsdl));
            $soapWrapper->setAuthentication('fail', 'doubleFail')->getPaymentMethods();
        } catch (Exception $e) {
            /** @noinspection NotOptimalIfConditionsInspection */
            // Skip test if this is not the expected error as API could fail.
            if ($e->getCode() !== 401 && $e->getCode() !== 2) {
                static::markTestSkipped(
                    sprintf(
                        'Error %s (%s) unexpectedly from the called API. Skipping for now.',
                        $e->getCode(),
                        $e->getMessage()
                    )
                );
                return;
            }

            // Assert "Unauthorized" code (401) or error code 2 based on E_WARNING
            static::assertTrue(
                $e->getCode() === 401 ||
                $e->getCode() === 2
            );
        }
    }

    /**
     * @test
     * @throws Exception
     */
    public function getSoapEmbeddedNoWsdl()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            // Service bails out on error 500 when ?wsdl is excluded.
            // For older PHP versions this renders a very noisy fatal.
            (new SoapClientWrapper($this->no_wsdl))
                ->setWsdlCache(WSDL_CACHE_DISK)
                ->setAuthentication(
                    $this->rEcomPipeU,
                    $this->rEcomPipeP
                )->getPaymentMethods();
        } catch (ExceptionHandler $e) {
            static::assertSame($e->getCode(), 500);
        }
    }

    /**
     * @test
     * @testdox Delayed request.
     */
    public function getSoapEmbeddedRequest()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            $wrapper = (new SoapClientWrapper())->setWsdlCache(WSDL_CACHE_DISK);
            $wrapper->setAuthentication(
                $this->rEcomPipeU,
                $this->rEcomPipeP
            );
            $result = $wrapper->request($this->wsdl)->getPaymentMethods();
            static::assertTrue(
                is_array($result) &&
                count($result)
            );
        } catch (ExceptionHandler $e) {
            if ($e->getCode() >= 500) {
                static::markTestIncomplete(
                    sprintf(
                        '%s test skipped due to not-our-fault--error %s: %s',
                        __FUNCTION__,
                        $e->getCode(),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * @test
     * @testdox Delayed request.
     * @throws ExceptionHandler
     */
    public function getSoapEmbeddedProxy()
    {
        if (!$this->canProxy()) {
            static::markTestSkipped('Can not perform proxy tests with this client. Skipped.');
            return;
        }

        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        // ['proxy_login' => 'not_necessary']

        try {
            $wrapper = (new SoapClientWrapper())
                ->setProxy('212.63.208.8:80')
                ->setWsdlCache(WSDL_CACHE_BOTH);
            $wrapper->setAuthentication(
                $this->rEcomPipeU,
                $this->rEcomPipeP
            );
            $result = $wrapper->request($this->wsdl)->getPaymentMethods();
        } catch (Exception $e) {
            static::markTestSkipped(
                sprintf(
                    '%s failed: %s (%s).',
                    __FUNCTION__,
                    $e->getMessage(),
                    $e->getCode()
                )
            );
            return;
        }
        static::assertTrue(
            is_array($result) &&
            count($result)
        );
    }

    /**
     * @test
     * @link https://www.php.net/manual/en/context.http.php
     */
    public function getSoapEmbeddedRequestTimeoutUncached()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        $wrapper = (new SoapClientWrapper())->setTimeout(0);
        $wrapper->setAuthentication(
            $this->rEcomPipeU,
            $this->rEcomPipeP
        );
        try {
            $result = $wrapper->request($this->wsdl)->getPaymentMethods();
            static::assertTrue(
                is_array($result) &&
                count($result)
            );
        } catch (Exception $e) {
            static::assertSame($e->getCode(), 2);
        }
    }

    /**
     * @test
     * @testdox While testing the wsdlcache, we also testing header, body and parser.
     * @throws ExceptionHandler
     */
    public function setWsdlCache()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            $wrapper = new SoapClientWrapper($this->wsdl);
            // PHP 7.0 Generates an invisible exit code when touching the wsdl cache.
            $wrapper->setStaging(false);
            $wrapper->setProduction(true);
            $wrapper->setAuthentication(
                $this->rEcomPipeU,
                $this->rEcomPipeP
            )->getPaymentMethods();

            $parsed = $wrapper->getParsed();
            $body = $wrapper->getBody();
            $headers = $wrapper->getHeaders(true, true);
            static::assertTrue(
                is_array($parsed) && count($parsed) &&
                is_string($body) && strlen($body) &&
                (
                    (
                        is_string($headers) && strlen($headers)
                    ) || is_array($headers)
                )
            );
        } catch (ExceptionHandler $e) {
            if ($e->getCode() >= 500) {
                static::markTestIncomplete(
                    sprintf(
                        '%s test skipped due to not-our-fault--error %s: %s',
                        __FUNCTION__,
                        $e->getCode(),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * @test
     * Testing soapwrapper with outgoing parameters.
     */
    public function setSoapValue()
    {
        if (defined('SKIP_SOAP')) {
            static::markTestSkipped('SoapClient is missing or disabled on demand. Test marked as skipped.');
            return;
        }
        try {
            $diffTestStamp = time() - 600;

            // Expected result.
            $currentTimeStamp = time();

            // Prepare SOAP-wrapper.
            /** @var SoapClientWrapper $rWrapper */
            $rWrapper = (new SoapClientWrapper($this->wsdl_config))
                ->setWsdlCache(WSDL_CACHE_DISK, 60)
                ->setAuthentication(
                    $this->rEcomPipeU,
                    $this->rEcomPipeP
                );

            // Send an url to our upstream soap instance.
            $rWrapper->registerEventCallback(
                [
                    'eventType' => 'BOOKED',
                    'uriTemplate' => sprintf(
                        'https://www.netcurl.org/?callback=BOOKED&ts=%d',
                        $currentTimeStamp
                    ),
                ]
            );

            // Dicover the change.
            parse_str(
                parse_url(
                    $rWrapper->getRegisteredEventCallback(
                        ['eventType' => 'BOOKED']
                    )->uriTemplate
                )['query'],
                $uriTemplate
            );

            static::assertTrue((int)$uriTemplate['ts'] > $diffTestStamp);
        } catch (Exception $e) {
            static::markTestSkipped(
                sprintf(
                    "Skipped %s due to exception %s (%s).\n" .
                    "If this is a pipeline test, this could be the primary cause of the problem.",
                    __FUNCTION__,
                    $e->getCode(),
                    $e->getMessage()
                )
            );
        }
    }
}
