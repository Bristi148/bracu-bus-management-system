<?php


class PHPMailer {
    public $Host       = '';
    public $SMTPAuth   = false;
    public $Username   = '';
    public $Password   = '';
    public $SMTPSecure = 'tls';
    public $Port       = 587;
    public $From       = '';
    public $FromName   = '';
    public $Subject    = '';
    public $Body       = '';
    public $ErrorInfo  = '';
    private $to        = [];
    private $html      = false;

    public function isSMTP()        { }
    public function isHTML($v=true) { $this->html = $v; }
    public function setFrom($e,$n=''){ $this->From=$e; $this->FromName=$n; }
    public function addAddress($e,$n=''){ $this->to[]=[$e,$n]; }

    public function send() {
        try {
            $host = ($this->SMTPSecure==='ssl') ? "ssl://{$this->Host}" : $this->Host;
            $sock = @fsockopen($host, $this->Port, $en, $es, 15);
            if (!$sock) { $this->ErrorInfo="Connect failed: $es"; return false; }
            stream_set_timeout($sock, 15);

            $this->r($sock); // greeting

            $this->c($sock,"EHLO localhost");
            $this->flush($sock);

            if ($this->SMTPSecure==='tls') {
                $this->c($sock,"STARTTLS");
                $this->r($sock);
                stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->c($sock,"EHLO localhost");
                $this->flush($sock);
            }

            if ($this->SMTPAuth) {
                $this->c($sock,"AUTH LOGIN");   $this->r($sock);
                $this->c($sock,base64_encode($this->Username)); $this->r($sock);
                $this->c($sock,base64_encode($this->Password));
                $a=$this->r($sock);
                if (substr($a,0,3)!=='235'){ $this->ErrorInfo="Auth failed: $a"; fclose($sock); return false; }
            }

            $this->c($sock,"MAIL FROM:<{$this->From}>"); $this->r($sock);
            foreach ($this->to as [$e,$n]) { $this->c($sock,"RCPT TO:<$e>"); $this->r($sock); }
            $this->c($sock,"DATA"); $this->r($sock);

            $ct   = $this->html ? 'text/html' : 'text/plain';
            $from = $this->FromName ? "\"{$this->FromName}\" <{$this->From}>" : $this->From;
            $to   = implode(', ', array_map(fn($r)=>$r[1]?"\"$r[1]\" <$r[0]>":$r[0], $this->to));
            $body = str_replace(["\r\n","\n"],"\r\n",$this->Body);
            $body = preg_replace('/^\./m','..',$body);

            fputs($sock,"From: $from\r\nTo: $to\r\nSubject: {$this->Subject}\r\n".
                        "MIME-Version: 1.0\r\nContent-Type: $ct; charset=UTF-8\r\n\r\n$body\r\n.\r\n");
            $res=$this->r($sock);
            $this->c($sock,"QUIT"); fclose($sock);

            if (substr($res,0,3)==='250') return true;
            $this->ErrorInfo="Send failed: $res"; return false;
        } catch(Exception $e){ $this->ErrorInfo=$e->getMessage(); return false; }
    }

    private function c($s,$cmd){ fputs($s,"$cmd\r\n"); }
    private function r($s){ $o=''; while($l=fgets($s,512)){ $o.=$l; if(isset($l[3])&&$l[3]===' ') break; } return trim($o); }
    private function flush($s){ $o=''; while($l=fgets($s,512)){ $o.=$l; if(isset($l[3])&&$l[3]===' ') break; } return $o; }
}