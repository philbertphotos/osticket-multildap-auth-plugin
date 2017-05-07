<?php
// #################################################################################
//
// File : mail.class.inc.php
// Class Description : This class is used to produce HTML format emails, with
// the ability to attach files and embed images.
//
/*
 * Mail API
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 2.1 beta
 */
// #################################################################################
class pssm_Mail {
    /**
     * @var int $_wrap
     */
    protected $_wrap = 78;

    protected $_to = array();
    protected $_subject;
    protected $_message;
    protected $_headers = array();
    protected $_params;
    protected $_attachments = array();
    protected $_uid;
    public function __construct() {
        $this->reset();
    }
    /**
     * reset
     * Resets all properties to initial state.
     */
    public function reset() {
        $this->_to          = array();
        $this->_headers     = array();
        $this->_subject     = null;
        $this->_message     = null;
        $this->_wrap        = 78;
        $this->_params      = null;
        $this->_attachments = array();
        $this->_uid         = $this->getUniqueId();
        $this->_reluid      = $this->getUniqueId();
        $this->_altuid      = $this->getUniqueId();
        return $this;
    }
    // Line Break BR
    /*  if(!defined('BR')){
    define('BR', "\r\n", TRUE);
    }*/

    /**
     * setTo
     * @param string $email The email address to send to.
     * @param string $name  The name of the person to send to.
     */
    public function setTo($email, $name='') {
        $this->_to[] = $this->formatHeader((string) $email, (string) $name);
        return $this;
    }
    /**
     * getTo
     * Return an array of formatted To addresses.
     */
    public function getTo() {
        return $this->_to;
    }
    /**
     * setSubject
     * @param string $subject The email subject
     */
    public function setSubject($subject) {
        $this->_subject = ($this->filterOther((string) $subject));
        return $this;
    }
    /**
     * getSubject function.
     * @return string
     */
    public function getSubject() {
        return $this->_subject;
    }
    /**
     * setMessage
     * @param string $message The message to send.
     */
    public function setMessage($message, $inline = false) {
        if ($inline) {
           $this->_message = $this->getBase64Image($message);
        } else {
            $this->_message = preg_replace("(\r\n|\r|\n)", PHP_EOL, $message);
        }
        return $this;
    }
    
    /**
     * getMessage
     * @return string
     */
    public function getMessage() {
        return $this->_message;
    }
    
    function getBase64Image($message) {
        $str;
        if (!empty($message)) {
            preg_match_all('/<img[^>]+>/i', stripcslashes($message), $imgTags);
            //All img tags
            for ($i = 0; $i < count($imgTags[0]); $i++) {
                preg_match('/src="([^"]+)/i', $imgTags[0][$i], $withSrc);
                //Remove src
                $withoutSrc = str_ireplace('src="', '', $withSrc[0]);
                
                //data:image/png;base64,
                if (strpos($withoutSrc, ";base64,")) {
                    //data:image/png;base64,.....
                    list($type, $data) = explode(";base64,", $withoutSrc);
                    //data:image/png
                    list($part, $ext) = explode("/", $type);
                $cid = $this->addEmbedbase64($data, $ext);
                $str = str_replace($withoutSrc, 'cid:' . $cid, $message);
                $str = preg_replace("(\r\n|\r|\n)", PHP_EOL, $str);
                }
            }
        }
        return ($str ? $str : $message);
    }
    /**
     * addAttachment
     *
     * @param string $path     The file path to the attachment.
     * @param string $filename The filename of the attachment when emailed.
     */
    public function addAttachment($path, $filename = null) {
        $filename             = empty($filename) ? basename($path) : $filename;
        $this->_attachments[] = array(
            'path' => $path,
            'file' => $filename,
            'size' => filesize($filename),
            'data' => $this->getAttachmentData($path)
        );
        return $this;
    }
    /**
     * addEmbedbase64
     * @param string $path     The file path to the attachment.
     * @param string $filename The filename of the attachment when emailed.
     */
    public function addEmbedbase64($data, $ext) {
        $basename             = $this->getRandomFilename();
        $filename             = $basename . '.' . $ext;
        $cid                  = md5(uniqid(time()));
        $this->_attachments[] = array(
            'cid' => $cid,
            'file' => $filename,
            'ext' => $ext,
            'size' => strlen(base64_decode($data)),
            'data' => chunk_split($data)
        );
        return $cid;
    }
    /**
     * getAttachmentData
     * @param string $path The path to the attachment file.
     */
    public function getAttachmentData($path) {
        $filesize   = filesize($path);
        $handle     = fopen($path, "r");
        $attachment = fread($handle, $filesize);
        fclose($handle);
        return chunk_split(base64_encode($attachment));
    }
    /**
     * getRandomFilename
     * @param string $path The path to the attachment file.
     */
    function getRandomFilename() {
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789_";
        $name  = "";
        for ($i = 0; $i < 12; $i++)
            $name .= $chars[rand(0, strlen($chars))];
        $name .= $chars[rand(0, strlen($chars))];
        return $name;
    }
    /**
     * setFrom
     * @param string $email The email to send as from.
     * @param string $name  The name to send as from.
     */
    public function setFrom($email, $name='') {
        $this->addMailHeader('From', (string) $email, (string) $name);
        return $this;
    }
    /**
     * addMailHeader
     * @param string $header The header to add.
     * @param string $email  The email to add.
     * @param string $name   The name to add.
     */
    public function addMailHeader($header, $email = null, $name = null) {
        $address          = $this->formatHeader((string) $email, (string) $name);
        $this->_headers[] = sprintf('%s: %s', (string) $header, $address);
        return $this;
    }
    /**
     * addGenericHeader
     * @param string $header The generic header to add.
     * @param mixed  $value  The value of the header.
     */
    public function addGenericHeader($header, $value) {
        $this->_headers[] = sprintf('%s: %s', (string) $header, (string) $value);
        return $this;
    }
    /**
     * getHeaders
     * Return the headers registered so far as an array.
     */
    public function getHeaders() {
        return $this->_headers;
    }
    /**
     * setAdditionalParameters
     * Such as "-f youremail@yourserver.com
      * @param string $additionalParameters The addition mail parameter.
     */
    public function setParameters($additionalParameters) {
        $this->_params = (string) $additionalParameters;
        return $this;
    }
    /**
     * getAdditionalParameters
     */
    public function getParameters() {
        return $this->_params;
    }
    /**
     * setWrap
     * @param int $wrap The number of characters at which the message will wrap.
     */
    public function setWrap($wrap = 78) {
        $wrap = (int) $wrap;
        if ($wrap < 1) {
            $wrap = 78;
        }
        $this->_wrap = $wrap;
        return $this;
    }
    /**
     * getWrap
     */
    public function getWrap() {
        return $this->_wrap;
    }
    /**
     * hasAttachments
     * Checks if the email has any registered attachments and returns bool
     */
    public function hasAttachments() {
        return !empty($this->_attachments);
    }
    /**
     * assemble Message Headers
    */
    
    public function assembleMesageHeaders() {
        $head   = array();
        //$head[] = "Message-Id: <" . md5(uniqid(rand())) . ".pssm@"">";
        $head[] = "MIME-Version: 1.0";
        if ($this->hasAttachments()) {
            $head[] = "Content-Type: multipart/related; boundary=\"{$this->_uid}\"";
        } else {
            $head[] = "Content-Type: multipart/alternative; boundary=\"{$this->_uid}\"";
        }
        return join(PHP_EOL, $head);
    }
    /**
     * assembleAttachmentBody
     */
    public function assembleAttachmentBody() {
        $body   = array();
        //$body[] = "This is a multi-part message in MIME format.";
        $body[] = "--{$this->_uid}";
        $body[] = "Content-Type: multipart/alternative; boundary=\"{$this->_altuid}\"" . PHP_EOL;
        $body[] = "--{$this->_altuid}";
        $body[] = "Content-type:text/plain; charset=\"UTF-8\"";
        $body[] = "Content-Transfer-Encoding: 7bit" . PHP_EOL;
        $body[] = $this->strip_html_tags($this->getMessage()) . PHP_EOL;
        //$body[] = strip_html_tags($this->getMessage()) . PHP_EOL;
        $body[] = "--{$this->_altuid}";
        $body[] = "Content-type:text/html; charset=\"UTF-8\"";
        $body[] = "Content-Transfer-Encoding: 7bit" . PHP_EOL;
        $body[] = $this->getMessage() . PHP_EOL;
        $body[] = "--{$this->_altuid}--" . PHP_EOL;
        
        foreach ($this->_attachments as $attachment) {
            $body[] = $this->getAttachmentMimeTemplate($attachment);
        }
        
        $body[] = "--{$this->_uid}--";
        
        
        return implode(PHP_EOL, $body);
    }
    
    public function assembleHtmlBody() {
        $body   = array();
        $body[] = "This is a multi-part message in MIME format." . PHP_EOL;
        $body[] = "--{$this->_uid}";
        $body[] = "Content-type:text/plain; charset=\"ISO-8859-1\"";
        $body[] = "Content-Transfer-Encoding: 7bit" . PHP_EOL;
        $body[] = $this->strip_html_tags($this->getMessage()) . PHP_EOL;
        $body[] = "--{$this->_uid}";
        $body[] = "Content-type:text/html; charset=\"ISO-8859-1\"";
        $body[] = "Content-Transfer-Encoding: 7bit" . PHP_EOL;
        $body[] = $this->getMessage() . PHP_EOL;
        $body[] = "--{$this->_uid}--";
        
        return implode(PHP_EOL, $body);
    }
    /**
     * getAttachmentMimeTemplate
     *
     * @param array  $attachment An array containing 'file' and 'data' keys.
     * @param string $uid        A unique identifier for the boundary.
     */
    public function getAttachmentMimeTemplate($attachment) {
        $file   = $attachment['file'];
        $data   = $attachment['data'];
        $cid    = $attachment['cid'];
        $ext    = $attachment['ext'];
        $size   = $attachment['size'];
        $head   = array();
        $head[] = "--{$this->_uid}";
        
        if (!empty($cid)) {
            $head[] = "Content-ID: <{$cid}>";
            $head[] = "Content-Type: image/{$ext}; name=\"{$file}\"; size={$size};";
            //$head[] = "Content-Description: $file";
            $head[] = "Content-Disposition: attachment; filename=\"{$file}\"";
            $head[] = "Content-Transfer-Encoding: base64" . PHP_EOL;
        } else {
            $head[] = "Content-Disposition: attachment; filename=\"{$file}\"";
            $head[] = "Content-Transfer-Encoding: base64" . PHP_EOL;
            $head[] = "Content-Type:" . getMimeType($file) . "; name=\"{$file}\"";
        }
        
        $head[] = $data . PHP_EOL;
        
        return implode(PHP_EOL, $head);
    }
    /**
     * send
     *
     * @throws \RuntimeException on no 'To: ' address to send to.
     * @return boolean
     */
    public function send() {
        $to      = $this->getToForSend();
        $headers = $this->getHeadersForSend();
        if (empty($to)) {
            throw new RuntimeException('Unable to send, no To address has been set.');
        }
        // $message = $this->getWrapMessage();
        $headers .= PHP_EOL . $this->assembleMesageHeaders();
        
        if ($this->hasAttachments()) {
            $message = $this->assembleAttachmentBody();
        } else {
            $message = $this->assembleHtmlBody();
            
        }
        //echo '<xmp>' . $headers . '</xmp>';
        //echo '<xmp>' . $message . '</xmp>';
        
        return mail($to, $this->_subject, $message, $headers, $this->_params);
    }
    /**
     * debug
     */
    public function debug() {
        return '<pre>' . print_r($this) . '</pre>';
    }
    /**
     * magic __toString function
     */
    public function __toString() {
        return print_r($this, true);
    }
    /**
     * formatHeader
     *
     * Formats a display address for emails according to RFC2822 e.g.
     * Name <address@domain.tld>
     *
     * @param string $email The email address.
     * @param string $name  The display name.
     */
    public function formatHeader($email, $name = null) {
        $email = $this->filterEmail($email);
        if (empty($name)) {
            return $email;
        }
        // $name = $this->encodeUtf8($this->filterName($name));
        $name = ($this->filterName($name));
        return sprintf('"%s" <%s>', $name, $email);
    }
    /**
     * encodeUtf8
     * @param string $value The value to encode.
     */
    public function encodeUtf8($value) {
        $value = trim($value);
        if (preg_match('/(\s)/', $value)) {
            return $this->encodeUtf8Words($value);
        }
        return $this->encodeUtf8Word($value);
    }
    /**
     * encodeUtf8Word
     * @param string $value The word to encode.
     */
    public function encodeUtf8Word($value) {
        return sprintf('=?UTF-8?B?%s?=', base64_encode($value));
    }
    /**
     * encodeUtf8Words
     * @param string $value The words to encode.
     */
    public function encodeUtf8Words($value) {
        $words   = explode(' ', $value);
        $encoded = array();
        foreach ($words as $word) {
            $encoded[] = $this->encodeUtf8Word($word);
        }
        return join($this->encodeUtf8Word(' '), $encoded);
    }
    /**
     * filterEmail
     * Removes any carriage return, line feed, tab, double quote, comma
     * and angle bracket characters before sanitizing the email address.
     * @param string $email The email to filter.
     */
    public function filterEmail($email) {
        $rule  = array(
            "\r" => '',
            "\n" => '',
            "\t" => '',
            '"' => '',
            ',' => '',
            '<' => '',
            '>' => ''
        );
        $email = strtr($email, $rule);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return $email;
    }
    
    function getMimeType($file) {
        $mime_types = array(
            "pdf" => "application/pdf",
            "exe" => "application/octet-stream",
            "zip" => "application/zip",
            "docx" => "application/msword",
            "doc" => "application/msword",
            "xls" => "application/vnd.ms-excel",
            "ppt" => "application/vnd.ms-powerpoint",
            "gif" => "image/gif",
            "png" => "image/png",
            "jpeg" => "image/jpg",
            "jpg" => "image/jpg",
            "mp3" => "audio/mpeg",
            "wav" => "audio/x-wav",
            "mpeg" => "video/mpeg",
            "mpg" => "video/mpeg",
            "mpe" => "video/mpeg",
            "mov" => "video/quicktime",
            "avi" => "video/x-msvideo",
            "3gp" => "video/3gpp",
            "css" => "text/css",
            "jsc" => "application/javascript",
            "js" => "application/javascript",
            "php" => "text/html",
            "htm" => "text/html",
            "html" => "text/html"
        );
        $extension  = strtolower(end(explode('.', $file)));
        return $mime_types[$extension];
    }
        /**
     * Remove HTML tags, including invisible text such as style and
     * script code, and embedded objects.  Add line breaks around
     * block-level tags to prevent word joining after tag removal.
     */
    public function strip_html_tags( $message ) {
    $message = preg_replace(
        array(
            // Remove invisible content
            '@<head[^>]*?>.*?</head>@siu',
            '@<style[^>]*?>.*?</style>@siu',
            '@<script[^>]*?.*?</script>@siu',
            '@<object[^>]*?.*?</object>@siu',
            '@<embed[^>]*?.*?</embed>@siu',
            '@<applet[^>]*?.*?</applet>@siu',
            '@<noframes[^>]*?.*?</noframes>@siu',
            '@<noscript[^>]*?.*?</noscript>@siu',
            '@<noembed[^>]*?.*?</noembed>@siu',

            // Add line breaks before & after blocks
            '@<((br)|(hr))@iu',
            '@</?((address)|(blockquote)|(center)|(del))@iu',
            '@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
            '@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
            '@</?((table)|(th)|(td)|(caption))@iu',
            '@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
            '@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
            '@</?((frameset)|(frame)|(iframe))@iu',
        ),
        array(
            ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',
            "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0",
            "\n\$0", "\n\$0",
        ),
        $message );

    // Remove all remaining tags and comments and return.
    return strip_tags( $message );
}
    /**
     * filterName
     *
     * Removes any carriage return, line feed or tab characters. Replaces
     * double quotes with single quotes and angle brackets with square
     * brackets, before sanitizing the string and stripping out html tags.
     *
     * @param string $name The name to filter.
     */
    public function filterName($name) {
        $rule     = array(
            "\r" => '',
            "\n" => '',
            "\t" => '',
            '"' => "'",
            '<' => '[',
            '>' => ']'
        );
        $filtered = filter_var($name, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        return trim(strtr($filtered, $rule));
    }
    /**
     * filterOther
     * Removes ASCII control characters including any carriage return, line
     * feed or tab characters.
     * @param string $data The data to filter.
     */
    public function filterOther($data) {
        return filter_var($data, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
    }
    /**
     * getHeadersForSend
     */
    public function getHeadersForSend() {
        if (empty($this->_headers)) {
            return '';
        }
        return join(PHP_EOL, $this->_headers);
    }
    /**
     * getToForSend
     */
    public function getToForSend() {
        if (empty($this->_to)) {
            return '';
        }
        return join(', ', $this->_to);
    }
    /**
     * getUniqueId
     */
    public function getUniqueId() {
        return md5(uniqid(time()));
    }
    /**
     * getWrapMessage
     */
    public function getWrapMessage() {
        return wordwrap($this->_message, $this->_wrap);
    }
}
?>
