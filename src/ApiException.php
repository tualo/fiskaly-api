<?php

namespace Tualo\Office\FiskalyAPI;

use Garden\Cli\Cli;
use Tualo\Office\Basic\TualoApplication;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;


class ApiException extends \Exception
{
    private int $responseCode = 0;
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct($message = "", $code = 0, \Throwable|null $previous = null)
    {
        parent::__construct($message, $code, $previous);
        if ($previous instanceof ClientException || $previous instanceof ServerException) {
            $this->responseCode = $previous->getResponse()->getStatusCode();
        } else if ($previous instanceof \GuzzleHttp\Exception\RequestException) {
            $this->responseCode = $previous->getResponse()->getStatusCode();
        }
    }
    public function getResponseCode(): int
    {
        return $this->responseCode;
    }
}
