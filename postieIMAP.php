<?php
/**
 * @author Dirk Elmendorf
 * @style Compliant
 * @testframework Compliant
 * @package Postie 
 * @copyright Copyright 2005 Dirk Elmendorf
 */

/**
  * This class handles the details of an IMAP connection
 *
 * @author Dirk Elmendorf
 * @package Postie
 */
class PostieIMAP {

    var $_connected;
    var $_protocol;
    var $_ssl;
    var $_self_cert;
    var $_tls_on;
    var $_connection;

    function PostieIMAP($protocol = "imap",$ssl_on = false,$self_cert = true) {
        $this->_connected = false;
        $this->_tls_on = false;
        $this->_protocol = strtolower($protocol);
        $this->_ssl = $ssl_on;
        $this->_self_cert = $self_cert;
    }
    /**
      *call this to turn on TLS
      */
    function TLSOn() {
        $this->_tls_on = true;
    }
    /** 
      * call this if you want to verify the cert
      */
    function RealCert() {
        $this->self_cert = false;
    }
    /**
      * Shows if the object is actually connected
      *@return boolean
      */
    function isConnected() {
        return($this->_connected);
    }
    /**
      *Opens a connection to the server
      *@return boolean
      */
    function connect($server,$port,$login,$password) {
        $option = "/service=".$this->_protocol;
        
        if ($this->_ssl) {
            $option .= "/ssl";
        }
        if ($this->_tls_on) {
            $option .= "/tls";
        }
        else {
            $option .= "/notls";
        }
        if ($this->_self_cert) {
            $option .= "/novalidate-cert";
        }
        if (eregi("google",$server) {
            //Fix from Jim Hodgson http://www.jimhodgson.com/2006/07/19/postie/
            $server_string = "{".$server.":".$port.$option."}INBOX";
        }
        else {
            $server_string = "{".$server.":".$port.$option."}";
        }
        $this->_connection = imap_open($server_string,$login,$password);

        if ($this->_connection) {
            $this->_connected = true;
        }
        return($this->_connected);
    }
    /**
      * Returns a count of the number of messages
      * @return integer
      */
    function getNumberOfMessages() {
        return(imap_num_msg($this->_connection));
    }
    /**
      * Gets the raw email message from the server
      * @return string
      */
    function fetchEmail($index){
        if ($index < 1 || $index > ($this->getNumberOfMessages() + 1)) {
            die("Invalid IMAP/POP3 message index!");
        }
        $email = imap_fetchheader($this->_connection,$index);
        $email .= imap_body($this->_connection,$index);
        return($email);
    }
    /**
      * Marks a message for deletion
      */
    function deleteMessage($index){
        imap_delete($this->_connection,$index);
    }
    /**
      * Handles purging any files that are marked for deletion
      */
    function expungeMessages(){
        imap_expunge($this->_connection); 
    }
    /**
      * Handles disconnecting from the server
      */
    function disconnect(){
        imap_close($this->_connection);
        $this->_connection = false;
    }
    /**
      *@return string
      */
    function error() {
        return(imap_last_error());
    }
    /**
      * Handles returning the right kind of object
      * @return PostieIMAP|PostieIMAPSSL|PostimePOP3SSL
      * @static
      */
    function &Factory($protocol) {
        switch(strtolower($protocol)) {
            case "imap":
                $object = &new PostieIMAP();
                break;
            case "imap-ssl":
                $object = &new PostieIMAPSSL();
                break;
            case "pop3-ssl":
                $object = &new PostiePOP3SSL();
                break;
            default:
                die("$protocol not supported");
        }
        return($object);
    }
}

/**
  * This class handles the details of an IMAP-SSL connection
 *
 * @author Dirk Elmendorf
 * @package Postie
 */
class PostieIMAPSSL  Extends PostieIMAP{

    function PostieIMAPSSL($protocol = "imap",$ssl_on = true,$self_cert = true) {
        PostieIMAP::PostieIMAP($protocol,$ssl_on,$self_cert);
    }
}

/**
  * This class handles the details of an POP3-SSL connection
 *
 * @author Dirk Elmendorf
 * @package Postie
 */
class PostiePOP3SSL Extends PostieIMAP {

    function PostiePOP3SSL($protocol = "pop3",$ssl_on = true,$self_cert = true) {
        PostieIMAP::PostieIMAP($protocol,$ssl_on,$self_cert);
    }
}
?>
