<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * An error status from the API. Subclasses name the common statuses; other statuses raise this
 * class itself.
 *
 * getMessage() reads like `HTTP 429: too many requests; slow down (request id: 1a2b...)`;
 * getApiMessage() is the API's own message, getCode() and getStatus() the HTTP status.
 */
class ApiException extends PdfConversionApiException
{
    private readonly int $status;
    private readonly string $apiMessage;
    private readonly ?string $requestId;
    private readonly ?int $retryAfter;
    private readonly string $body;

    public function __construct(
        int $status,
        string $apiMessage,
        ?string $requestId = null,
        ?int $retryAfter = null,
        string $body = '',
        ?\Throwable $previous = null,
    ) {
        $this->status = $status;
        $this->apiMessage = $apiMessage;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
        $this->body = $body;
        $text = 'HTTP ' . $status . ': ' . $apiMessage;
        if ($requestId !== null) {
            $text .= ' (request id: ' . $requestId . ')';
        }
        parent::__construct($text, $status, $previous);
    }

    /** The HTTP status code, such as 404. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The API's message, unchanged: the JSON body's Message, `empty response`, a short plain-text
     * body, or `non-JSON response (<content type>)`. Message texts can change; branch on the status.
     */
    public function getApiMessage(): string
    {
        return $this->apiMessage;
    }

    /** The x-request-id header, or null when the response didn't come from the API itself. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** Seconds from the Retry-After header, on 429 and 503 only (1 when missing or unreadable). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** The raw response body, for debugging. */
    public function getBody(): string
    {
        return $this->body;
    }
}
