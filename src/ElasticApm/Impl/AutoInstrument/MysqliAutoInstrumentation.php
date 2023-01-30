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
final class MysqliAutoInstrumentation extends AutoInstrumentationBase
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
        return 'mysqli';
    }

    /** @inheritDoc */
    public function otherNames(): array
    {
        return [InstrumentationNames::DB];
    }

    /** @inheritDoc */
    public function register(RegistrationContextInterface $ctx): void
    {
        if (!extension_loaded('mysqli')) {
            return;
        }

        $this->mysqliQuery($ctx); // mysqli_query( $dbh, $query )
    }

    private function mysqliQuery(RegistrationContextInterface $ctx): void
    {
        $ctx->interceptCallsToFunction(
            'mysqli_query',
            /**
             * @param mixed[]     $interceptedCallArgs Intercepted call arguments
             *
             * @return callable
             */
            function (array $interceptedCallArgs): ?callable {
                if (count($interceptedCallArgs) != 2) {
                    ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
                    && $loggerProxy->log(
                        'Number of received arguments for mysqli_query() is not the expected 2.',
                        ['interceptedCallArgs' => $interceptedCallArgs]
                    );
                    return null; // no post-hook
                }

                $statement = $interceptedCallArgs[1];
                if (!is_string($statement)) {
                    ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                    && $loggerProxy->log(
                        'The second received argument for mysqli_query() is not a string.',
                        ['interceptedCallArgs' => $interceptedCallArgs]
                    );
                    $statement = null;
                }

                $span = $this->beginDbSpan($statement ?? ('mysqli_query'), $statement);

                return self::createPostHookFromEndSpan($span);
            }
        );
    }

    private function beginDbSpan(string $name, ?string $statement): SpanInterface
    {
        $span = ElasticApm::getCurrentTransaction()->beginCurrentSpan(
            $name,
            Constants::SPAN_TYPE_DB,
            Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL,
            Constants::SPAN_TYPE_DB_ACTION_QUERY
        );
        $span->context()->db()->setStatement(
            $statement
        );
        $span->context()->destination()->setService(
            Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL,
            Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL . '/mysqli',
            Constants::SPAN_TYPE_DB
        );
        $span->context()->service()->target()->setName(
            Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL . '/mysqli'
        );
        $span->context()->service()->target()->setType(
            Constants::SPAN_TYPE_DB
        );

        return $span;
    }

}
