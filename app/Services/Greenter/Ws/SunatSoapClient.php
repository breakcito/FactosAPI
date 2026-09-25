<?php

namespace App\Services\Greenter\Ws;

use Greenter\Ws\Services\SoapClient as BaseSoapClient;

class SunatSoapClient extends BaseSoapClient
{
    /**
     * @param  string  $user
     * @param  string  $password
     */
    public function setCredentials($user, $password): void
    {
        $this->__setSoapHeaders(new WSSESecurityHeader($user, $password));
    }
}
