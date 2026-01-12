<?php

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $timeout = 30;
    private $debug = false;

    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function send($to, $subject, $message, $headers = []) {
        $socket = fsockopen(($this->port == 465 ? "ssl://" : "") . $this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$socket) {
            throw new Exception("Error connecting to SMTP server: $errstr ($errno)");
        }

        $this->readResponse($socket, 220);

        $this->sendCommand($socket, "EHLO " . $_SERVER['HTTP_HOST'], 250);

        if ($this->username && $this->password) {
            $this->sendCommand($socket, "AUTH LOGIN", 334);
            $this->sendCommand($socket, base64_encode($this->username), 334);
            $this->sendCommand($socket, base64_encode($this->password), 235);
        }

        $this->sendCommand($socket, "MAIL FROM: <" . $this->username . ">", 250);
        $this->sendCommand($socket, "RCPT TO: <" . $to . ">", 250);
        $this->sendCommand($socket, "DATA", 354);

        $data = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $data .= "To: <$to>\r\n";
        $data .= "From: <" . $this->username . ">\r\n"; // Override me if needed via headers? No, SMTP strict usually
        
        foreach ($headers as $header) {
            $data .= $header . "\r\n";
        }
        
        $data .= "\r\n" . $message . "\r\n.";

        $this->sendCommand($socket, $data, 250);
        $this->sendCommand($socket, "QUIT", 221);

        fclose($socket);
        return true;
    }

    private function sendCommand($socket, $command, $expectedCode) {
        fputs($socket, $command . "\r\n");
        $this->readResponse($socket, $expectedCode);
    }

    private function readResponse($socket, $expectedCode) {
        $response = "";
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") { break; }
        }
        
        if ($this->debug) {
            // error_log("SMTP: $response"); 
        }

        if (substr($response, 0, 3) != $expectedCode) {
            throw new Exception("SMTP Error: $response");
        }
    }
}
