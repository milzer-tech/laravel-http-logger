<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Tests\Support;

use Milzer\SaloonLogger\Contracts\ConfiguresLogging;
use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Body\HasMultipartBody;
use Saloon\Traits\Body\HasStreamBody;
use Saloon\Traits\Body\HasXmlBody;

final class SearchRequest extends Request implements HasBody, ProvidesLogContext
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/accommodations/search';
    }

    protected function defaultQuery(): array
    {
        return ['lang' => 'de', 'api_key' => 'query-secret'];
    }

    protected function defaultBody(): array
    {
        return [
            'destination' => 'Mallorca',
            'guests' => [['name' => 'Jane', 'passport' => 'X123']],
            'payment' => ['cardNumber' => '4111111111111111', 'cvv' => '123', 'holder' => 'Jane Doe'],
        ];
    }

    public function logContext(PendingRequest $pendingRequest): array
    {
        return ['action' => 'search'];
    }
}

final class GetBookingRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/bookings/42';
    }
}

final class XmlRequest extends Request implements HasBody
{
    use HasXmlBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/soap';
    }

    protected function defaultBody(): string
    {
        return '<?xml version="1.0"?><Envelope><Auth user="bob" password="hunter2"/><ns:Password>p@ss</ns:Password><Hotel>Palma</Hotel></Envelope>';
    }
}

final class FormRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/oauth/token';
    }

    protected function defaultBody(): array
    {
        return ['grant_type' => 'client_credentials', 'client_id' => 'abc', 'client_secret' => 'shh'];
    }
}

final class UploadRequest extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/documents';
    }

    protected function defaultBody(): array
    {
        return [
            new MultipartValue('title', 'Voucher'),
            new MultipartValue('password', 'pdf-password'),
            new MultipartValue('file', str_repeat('%PDF', 256), 'voucher.pdf'),
        ];
    }
}

final class StreamRequest extends Request implements HasBody
{
    use HasStreamBody;

    protected Method $method = Method::PUT;

    public function __construct(private readonly mixed $stream) {}

    public function resolveEndpoint(): string
    {
        return '/upload';
    }

    protected function defaultBody(): mixed
    {
        return $this->stream;
    }
}

final class LoggedRequest extends Request implements ConfiguresLogging
{
    use HasLogging;

    protected Method $method = Method::GET;

    public function __construct(private readonly ?\Closure $configure = null) {}

    public function resolveEndpoint(): string
    {
        return '/configured';
    }

    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $this->configure ? ($this->configure)($options) : $options;
    }
}
