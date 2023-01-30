<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace Elastic\Apm\Impl\AutoInstrument;

use Elastic\Apm\ElasticApm;
use Elastic\Apm\Impl\Constants;
use Elastic\Apm\Impl\Log\LogCategory;
use Elastic\Apm\Impl\Log\Logger;
use Elastic\Apm\Impl\Tracer;
use Elastic\Apm\Impl\Util\TextUtil;
use Elastic\Apm\SpanInterface;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class WPDBAutoInstrumentation extends AutoInstrumentationBase
{
    use AutoInstrumentationTrait;

    /** @var Logger */
    private $logger;

    public function __construct(Tracer $tracer)
    {
        parent::__construct($tracer);

        $this->logger = $tracer->loggerFactory()->loggerForClass(
            LogCategory::AUTO_INSTRUMENTATION,
            __NAMESPACE__,
            __CLASS__,
            __FILE__
        )->addContext('this', $this);
    }

    /** @inheritDoc */
    public function name(): string
    {
        return 'wpdb';
    }

    /** @inheritDoc */
    public function otherNames(): array
    {
        return [InstrumentationNames::DB];
    }

    /** @inheritDoc */
    public function register(RegistrationContextInterface $ctx): void
    {
        if (!class_exists('wpdb')) {
            return;
        }

        $this->wpdbConstruct($ctx); // __construct( $dbuser, $dbpassword, $dbname, $dbhost )
        $this->wpdbQuery($ctx);     // query( $query )

        // $this->wpdbInsert($ctx);    // insert( $table, $data, $format = null )
        // $this->wpdbReplace($ctx);   // replace( $table, $data, $format = null )
        // $this->wpdbUpdate($ctx);    // update( $table, $data, $where, $format = null, $where_format = null )
        // $this->wpdbDelete($ctx);    // delete( $table, $where, $where_format = null )

    }

    private function wpdbConstruct(RegistrationContextInterface $ctx): void
    {
        $ctx->interceptCallsToMethod(
            'wpdb',
            '__construct',
            /**
             * @param object|null $interceptedCallThis Intercepted call $this
             * @param mixed[]     $interceptedCallArgs Intercepted call arguments
             *
             * @return callable
             */
            function (?object $interceptedCallThis, array $interceptedCallArgs): ?callable {
                $this->checkIntercept($interceptedCallThis, $interceptedCallArgs, '__construct', 4);
                return null; // no post-hook
            }
        );
    }

    private function wpdbQuery(RegistrationContextInterface $ctx): void
    {
        $ctx->interceptCallsToMethod(
            'wpdb',
            'query',
            /**
             * @param object|null $interceptedCallThis Intercepted call $this
             * @param mixed[]     $interceptedCallArgs Intercepted call arguments
             *
             * @return callable
             */
            function (?object $interceptedCallThis, array $interceptedCallArgs): ?callable {
                if (!($this->checkIntercept($interceptedCallThis, $interceptedCallArgs, 'query', 1))) {
                    return null; // no post-hook
                }

                $statement = $interceptedCallArgs[0];
                if (!is_string($statement)) {
                    ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                    && $loggerProxy->log(
                        'The first received argument for wpdb::query call is not a string'
                        . ' so statement cannot be captured',
                        ['interceptedCallThis' => $interceptedCallThis]
                    );
                    $statement = null;
                }

                if (is_a(self::getDynamicallyAttachedProperty(
                    $interceptedCallThis,
                    'dbh',
                    null
                ), 'mysqli')) {
                    $dbType = Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL;
                } else {
                    $dbType = Constants::SPAN_TYPE_DB_SUBTYPE_UNKNOWN;
                }

                /** @var ?string $dbName */
                $dbName = self::getDynamicallyAttachedProperty(
                    $interceptedCallThis,
                    'dbname',
                    null
                );

                $span = $this->beginDbSpan($statement ?? ('wpdb->query'), $dbType, $dbName, $statement);

                return self::createPostHookFromEndSpan($span);
            }
        );
    }

    private function beginDbSpan(string $name, string $dbType, ?string $dbName, ?string $statement): SpanInterface
    {
        $span = ElasticApm::getCurrentTransaction()->beginCurrentSpan(
            $name,
            Constants::SPAN_TYPE_DB,
            $dbType /* <- subtype */,
            Constants::SPAN_TYPE_DB_ACTION_QUERY
        );

        $span->context()->db()->setStatement($statement);

        self::setService($span, $dbType, $dbName);

        return $span;
    }

    private static function setService(SpanInterface $span, string $dbType, ?string $dbName): void
    {
        $destinationServiceResource = $dbType;
        if ($dbName !== null && !TextUtil::isEmptyString($dbName)) {
            $destinationServiceResource .= '/' . $dbName;
        }
        $span->context()->destination()->setService($destinationServiceResource, $destinationServiceResource, $dbType);
        $span->context()->service()->target()->setName($dbName);
        $span->context()->service()->target()->setType($dbType);
    }

    private function checkIntercept(?object $interceptedCallThis, array $interceptedCallArgs, string $methodName, int $methodArgs) : bool {
        if (!(is_a($interceptedCallThis, 'wpdb'))) {
            ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'interceptedCallThis is not an instance of class wpdb',
                ['interceptedCallThis' => $interceptedCallThis]
            );
            return false;
        }

        if (count($interceptedCallArgs) != $methodArgs) {
            ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'Number of received arguments for wpdb::' . $methodName . ' call is not expected.'
                . 'wpdb::' . $methodName . ' is expected to have ' . $methodArgs . ' arguments',
                ['interceptedCallThis' => $interceptedCallThis]
            );
            return false;
        }

        return true;
    }

}
