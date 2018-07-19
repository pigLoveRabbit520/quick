<?php
namespace Quick\Exception;

use Exception;


class QuickException extends Exception
{
    protected $request;

    /**
     * A response object to send to the HTTP client
     *
     * @var ResponseInterface
     */
    protected $response;


    public function __construct($request, $response)
    {
        parent::__construct();
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Get request
     *
     * @return ServerRequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get response
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }
}
