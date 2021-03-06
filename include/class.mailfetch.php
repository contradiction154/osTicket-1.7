<?php
/*********************************************************************
    class.mailfetch.php

    mail fetcher class. Uses IMAP ext for now.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once(INCLUDE_DIR.'class.mailparse.php');
require_once(INCLUDE_DIR.'class.ticket.php');
require_once(INCLUDE_DIR.'class.dept.php');
require_once(INCLUDE_DIR.'class.email.php');
require_once(INCLUDE_DIR.'class.filter.php');

class MailFetcher {

    var $ht;

    var $mbox;
    var $srvstr;

    var $charset = 'UTF-8';

    function MailFetcher($email, $charset='UTF-8') {


        if($email && is_numeric($email)) //email_id
            $email=Email::lookup($email);

        if(is_object($email))
            $this->ht = $email->getMailAccountInfo();
        elseif(is_array($email) && $email['host']) //hashtable of mail account info
            $this->ht = $email;
        else
            $this->ht = null;

        $this->charset = $charset;

        if($this->ht) {
            if(!strcasecmp($this->ht['protocol'],'pop')) //force pop3
                $this->ht['protocol'] = 'pop3';
            else
                $this->ht['protocol'] = strtolower($this->ht['protocol']);

            //Max fetch per poll
            if(!$this->ht['max_fetch'] || !is_numeric($this->ht['max_fetch']))
                $this->ht['max_fetch'] = 20;

            //Mail server string
            $this->srvstr=sprintf('{%s:%d/%s', $this->getHost(), $this->getPort(), $this->getProtocol());
            if(!strcasecmp($this->getEncryption(), 'SSL'))
                $this->srvstr.='/ssl';

            $this->srvstr.='/novalidate-cert}';

        }

        //Set timeouts
        if(function_exists('imap_timeout')) imap_timeout(1,20);

    }

    function getEmailId() {
        return $this->ht['email_id'];
    }

    function getHost() {
        return $this->ht['host'];
    }

    function getPort() {
        return $this->ht['port'];
    }

    function getProtocol() {
        return $this->ht['protocol'];
    }

    function getEncryption() {
        return $this->ht['encryption'];
    }

    function getUsername() {
        return $this->ht['username'];
    }

    function getPassword() {
        return $this->ht['password'];
    }

    /* osTicket Settings */

    function canDeleteEmails() {
        return ($this->ht['delete_mail']);
    }

    function getMaxFetch() {
        return $this->ht['max_fetch'];
    }

    function getArchiveFolder() {
        return $this->mailbox_encode($this->ht['archive_folder']);
    }

    /* Core */

    function connect() {
        return ($this->mbox && $this->ping())?$this->mbox:$this->open();
    }

    function ping() {
        return ($this->mbox && imap_ping($this->mbox));
    }

    /* Default folder is inbox - TODO: provide user an option to fetch from diff folder/label */
    function open($box='INBOX') {

        if ($this->mbox)
           $this->close();

        $args = array($this->srvstr.$this->mailbox_encode($box),
            $this->getUsername(), $this->getPassword());

        // Disable Kerberos and NTLM authentication if it happens to be
        // supported locally or remotely
        if (version_compare(PHP_VERSION, '5.3.2', '>='))
            $args += array(NULL, 0, array(
                'DISABLE_AUTHENTICATOR' => array('GSSAPI', 'NTLM')));

        $this->mbox = call_user_func_array('imap_open', $args);

        return $this->mbox;
    }

    function close($flag=CL_EXPUNGE) {
        imap_close($this->mbox, $flag);
    }

    function mailcount() {
        return count(imap_headers($this->mbox));
    }

    //Get mail boxes.
    function getMailboxes() {

        if(!($folders=imap_list($this->mbox, $this->srvstr, "*")) || !is_array($folders))
            return null;

        $list = array();
        foreach($folders as $folder)
            $list[]= str_replace($this->srvstr, '', imap_utf7_decode(trim($folder)));

        return $list;
    }

    //Create a folder.
    function createMailbox($folder) {

        if(!$folder) return false;

        return imap_createmailbox($this->mbox,
           $this->srvstr.$this->mailbox_encode(trim($folder)));
    }

    /* check if a folder exists - create one if requested */
    function checkMailbox($folder, $create=false) {

        if(($mailboxes=$this->getMailboxes()) && in_array(trim($folder), $mailboxes))
            return true;

        return ($create && $this->createMailbox($folder));
    }


    function decode($text, $encoding) {

        switch($encoding) {
            case 1:
            $text=imap_8bit($text);
            break;
            case 2:
            $text=imap_binary($text);
            break;
            case 3:
            // imap_base64 implies strict mode. If it refuses to decode the
            // data, then fallback to base64_decode in non-strict mode
            $text = (($conv=imap_base64($text))) ? $conv : base64_decode($text);
            break;
            case 4:
            $text=imap_qprint($text);
            break;
        }

        return $text;
    }

    //Convert text to desired encoding..defaults to utf8
    function mime_encode($text, $charset=null, $encoding='utf-8') { //Thank in part to afterburner
        return Charset::transcode($text, $charset, $encoding);
    }

    function mailbox_encode($mailbox) {
        if (!$mailbox)
            return null;
        // Properly encode the mailbox to UTF-7, according to rfc2060,
        // section 5.1.3
        elseif (function_exists('mb_convert_encoding'))
            return mb_convert_encoding($mailbox, 'UTF7-IMAP', 'utf-8');
        else
            // XXX: This function has some issues on some versions of PHP
            return imap_utf7_encode($mailbox);
    }

    /**
     * Mime header value decoder. Usually unicode characters are encoded
     * according to RFC-2047. This function will decode RFC-2047 encoded
     * header values as well as detect headers which are not encoded.
     *
     * Caveats:
     * If headers contain non-ascii characters the result of this function
     * is completely undefined. If osTicket is corrupting your email
     * headers, your mail software is not encoding the header text
     * correctly.
     *
     * Returns:
     * Header value, transocded to UTF-8
     */
    function mime_decode($text, $encoding='utf-8') {
        // Handle poorly or completely un-encoded header values (
        if (function_exists('mb_detect_encoding'))
            if (($src_enc = mb_detect_encoding($text))
                    && (strcasecmp($src_enc, 'ASCII') !== 0))
                return Charset::transcode($text, $src_enc, $encoding);

        // Handle ASCII text and RFC-2047 encoding
        $str = '';
        $parts = imap_mime_header_decode($text);
        foreach ($parts as $part)
            $str.= $this->mime_encode($part->text, $part->charset, $encoding);

        return $str?$str:imap_utf8($text);
    }

    function getLastError() {
        return imap_last_error();
    }

    function getMimeType($struct) {
        $mimeType = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        if(!$struct || !$struct->subtype)
            return 'TEXT/PLAIN';

        return $mimeType[(int) $struct->type].'/'.$struct->subtype;
    }

    function getHeaderInfo($mid) {

        if(!($headerinfo=imap_headerinfo($this->mbox, $mid)) || !$headerinfo->from)
            return null;

        $sender=$headerinfo->from[0];
        //Just what we need...
        $header=array('name'  =>@$sender->personal,
                      'email'  => trim(strtolower($sender->mailbox).'@'.$sender->host),
                      'subject'=>@$headerinfo->subject,
                      'mid'    => trim(@$headerinfo->message_id),
                      'header' => $this->getHeader($mid),
                      'in-reply-to' => $headerinfo->in_reply_to,
                      'references' => $headerinfo->references,
                      );

        if ($replyto = $headerinfo->reply_to) {
            $header['reply-to'] = $replyto[0]->mailbox.'@'.$replyto[0]->host;
            $header['reply-to-name'] = $replyto[0]->personal;
        }

        //Try to determine target email - useful when fetched inbox has
        // aliases that are independent emails within osTicket.
        $emailId = 0;
        $tolist = array();
        if($headerinfo->to)
            $tolist = array_merge($tolist, $headerinfo->to);
        if($headerinfo->cc)
            $tolist = array_merge($tolist, $headerinfo->cc);
        if($headerinfo->bcc)
            $tolist = array_merge($tolist, $headerinfo->bcc);

        foreach($tolist as $addr)
            if(($emailId=Email::getIdByEmail(strtolower($addr->mailbox).'@'.$addr->host)))
                break;

        $header['emailId'] = $emailId;

        // Ensure we have a message-id. If unable to read it out of the
        // email, use the hash of the entire email headers
        if (!$header['mid'] && $header['header']) {
            $header['mid'] = Mail_Parse::findHeaderEntry($header['header'],
                    'message-id');

            if (is_array($header['mid']))
                $header['mid'] = array_pop(array_filter($header['mid']));
            if (!$header['mid'])
                $header['mid'] = '<' . md5($header['header']) . '@local>';
        }

        return $header;
    }

    //search for specific mime type parts....encoding is the desired encoding.
    function getPart($mid, $mimeType, $encoding=false, $struct=null, $partNumber=false) {

        if(!$struct && $mid)
            $struct=@imap_fetchstructure($this->mbox, $mid);

        //Match the mime type.
        if($struct
                && strcasecmp($mimeType, $this->getMimeType($struct))==0
                && (!$struct->ifdparameters
                    || !$this->findFilename($struct->dparameters))) {

            $partNumber=$partNumber?$partNumber:1;
            if(($text=imap_fetchbody($this->mbox, $mid, $partNumber))) {
                if($struct->encoding==3 or $struct->encoding==4) //base64 and qp decode.
                    $text=$this->decode($text, $struct->encoding);

                $charset=null;
                if ($encoding) { //Convert text to desired mime encoding...
                    if ($struct->ifparameters && $struct->parameters) {
                        foreach ($struct->parameters as $p) {
                            if (!strcasecmp($p->attribute, 'charset')) {
                                $charset = trim($p->value);
                                break;
                            }
                        }
                    }
                    $text = $this->mime_encode($text, $charset, $encoding);
                }
                return $text;
            }
        }

        //Do recursive search
        $text='';
        if($struct && $struct->parts) {
            while(list($i, $substruct) = each($struct->parts)) {
                if($partNumber)
                    $prefix = $partNumber . '.';
                if(($result=$this->getPart($mid, $mimeType, $encoding, $substruct, $prefix.($i+1))))
                    $text.=$result;
            }
        }

        return $text;
    }

    /**
     * Searches the attribute list for a possible filename attribute. If
     * found, the attribute value is returned. If the attribute uses rfc5987
     * to encode the attribute value, the value is returned properly decoded
     * if possible
     *
     * Attribute Search Preference:
     *   filename
     *   filename*
     *   name
     *   name*
     */
    function findFilename($attributes) {
        foreach (array('filename', 'name') as $pref) {
            foreach ($attributes as $a) {
                if (strtolower($a->attribute) == $pref)
                    return $a->value;
                // Allow the RFC5987 specification of the filename
                elseif (strtolower($a->attribute) == $pref.'*')
                    return Format::decodeRfc5987($a->value);
            }
        }
        return false;
    }

    /*
     getAttachments

     search and return a hashtable of attachments....
     NOTE: We're not actually fetching the body of the attachment  - we'll do it on demand to save some memory.

     */
    function getAttachments($part, $index=0) {

        if($part && !$part->parts) {
            //Check if the part is an attachment.
            $filename = false;
            if ($part->ifdisposition && $part->ifdparameters
                    && in_array(strtolower($part->disposition),
                        array('attachment', 'inline'))) {
                $filename = $this->findFilename($part->dparameters);
            }
            // Inline attachments without disposition. NOTE that elseif is
            // not utilized here b/c ::findFilename may return null
            if (!$filename && $part->ifparameters && $part->parameters
                    && $part->type > 0) {
                $filename = $this->findFilename($part->parameters);
            }

            $content_id = ($part->ifid)
                ? rtrim(ltrim($part->id, '<'), '>') : false;

            // Some mail clients / servers (like Lotus Notes / Domino) will
            // send images without a filename. For such a case, generate a
            // random filename for the image
            if (!$filename && $content_id && $part->type == 5) {
                $filename = 'image-'.Misc::randCode(4).'.'.strtolower($part->subtype);
            }

            if($filename) {
                return array(
                        array(
                            'name'  => $this->mime_decode($filename),
                            'type'  => $this->getMimeType($part),
                            'encoding' => $part->encoding,
                            'index' => ($index?$index:1)
                            )
                        );
            }
        }

        //Recursive attachment search!
        $attachments = array();
        if($part && $part->parts) {
            foreach($part->parts as $k=>$struct) {
                if($index) $prefix = $index.'.';
                $attachments = array_merge($attachments, $this->getAttachments($struct, $prefix.($k+1)));
            }
        }

        return $attachments;
    }

    function getHeader($mid) {
        return imap_fetchheader($this->mbox, $mid,FT_PREFETCHTEXT);
    }


    function getPriority($mid) {
        return Mail_Parse::parsePriority($this->getHeader($mid));
    }

    function getBody($mid) {

        $body ='';
        if ($body = $this->getPart($mid,'TEXT/PLAIN', $this->charset))
            // The Content-Type was text/plain, so escape anything that
            // looks like HTML
            $body=Format::htmlchars($body);
        elseif ($body = $this->getPart($mid,'TEXT/HTML', $this->charset)) {
            //Convert tags of interest before we striptags
            $body=str_replace("</DIV><DIV>", "\n", $body);
            $body=str_replace(array("<br>", "<br />", "<BR>", "<BR />"), "\n", $body);
            $body=Format::safe_html($body); //Balance html tags & neutralize unsafe tags.
        }

        return $body;
    }

    //email to ticket
    function createTicket($mid) {
        global $ost;

        if(!($mailinfo = $this->getHeaderInfo($mid)))
            return false;

	    //Is the email address banned?
        if($mailinfo['email'] && TicketFilter::isBanned($mailinfo['email'])) {
	        //We need to let admin know...
            $ost->logWarning('Ticket denied', 'Banned email - '.$mailinfo['email'], false);
	        return true; //Report success (moved or delete)
        }

        $vars = $mailinfo;
        $vars['name']=$this->mime_decode($mailinfo['name']);
        $vars['subject']=$mailinfo['subject']?$this->mime_decode($mailinfo['subject']):'[No Subject]';
        $vars['message']=Format::stripEmptyLines($this->getBody($mid));
        $vars['emailId']=$mailinfo['emailId']?$mailinfo['emailId']:$this->getEmailId();

        //Missing FROM name  - use email address.
        if(!$vars['name'])
            $vars['name'] = $vars['email'];

        //An email with just attachments can have empty body.
        if(!$vars['message'])
            $vars['message'] = '-';

        if($ost->getConfig()->useEmailPriority())
            $vars['priorityId']=$this->getPriority($mid);

        $ticket=null;
        $newticket=true;

        $errors=array();
        $seen = false;

        if (($thread = ThreadEntry::lookupByEmailHeaders($vars, $seen))
                && ($message = $thread->postEmail($vars))) {
            if (!$message instanceof ThreadEntry)
                // Email has been processed previously
                return $message;
            $ticket = $message->getTicket();
        } elseif ($seen) {
            // Already processed, but for some reason (like rejection), no
            // thread item was created. Ignore the email
            return true;
        } elseif (($ticket=Ticket::create($vars, $errors, 'Email'))) {
            $message = $ticket->getLastMessage();
        } else {
            //Report success if the email was absolutely rejected.
            if(isset($errors['errno']) && $errors['errno'] == 403) {
                // Never process this email again!
                ThreadEntry::logEmailHeaders(0, $vars['mid']);
                return true;
            }

            # check if it's a bounce!
            if($vars['header'] && TicketFilter::isAutoBounce($vars['header'])) {
                $ost->logWarning('Bounced email', $vars['message'], false);
                return true;
            }

            //TODO: Log error..
            return null;
        }

        //Save attachments if any.
        if($message
                && $ost->getConfig()->allowEmailAttachments()
                && ($struct = imap_fetchstructure($this->mbox, $mid))
                && ($attachments=$this->getAttachments($struct))) {

            foreach($attachments as $a ) {
                $file = array('name'  => $a['name'], 'type'  => $a['type']);

                //Check the file  type
                if(!$ost->isFileTypeAllowed($file))
                    $file['error'] = 'Invalid file type (ext) for '.Format::htmlchars($file['name']);
                else //only fetch the body if necessary TODO: Make it a callback.
                    $file['data'] = $this->decode(imap_fetchbody($this->mbox, $mid, $a['index']), $a['encoding']);

                $message->importAttachment($file);
            }
        }

        return $ticket;
    }


    function fetchEmails() {


        if(!$this->connect())
            return false;

        $archiveFolder = $this->getArchiveFolder();
        $delete = $this->canDeleteEmails();
        $max = $this->getMaxFetch();

        $nummsgs=imap_num_msg($this->mbox);
        //echo "New Emails:  $nummsgs\n";
        $msgs=$errors=0;
        for($i=$nummsgs; $i>0; $i--) { //process messages in reverse.
            if($this->createTicket($i)) {

                imap_setflag_full($this->mbox, imap_uid($this->mbox, $i), "\\Seen", ST_UID); //IMAP only??
                if((!$archiveFolder || !imap_mail_move($this->mbox, $i, $archiveFolder)) && $delete)
                    imap_delete($this->mbox, $i);

                $msgs++;
                $errors=0; //We are only interested in consecutive errors.
            } else {
                $errors++;
            }

            if($max && ($msgs>=$max || $errors>($max*0.8)))
                break;
        }

        //Warn on excessive errors
        if($errors>$msgs) {
            $warn=sprintf('Excessive errors processing emails for %s/%s. Please manually check the inbox.',
                    $this->getHost(), $this->getUsername());
            $this->log($warn);
        }

        @imap_expunge($this->mbox);

        return $msgs;
    }

    function log($error) {
        global $ost;
        $ost->logWarning('Mail Fetcher', $error);
    }

    /*
       MailFetcher::run()

       Static function called to initiate email polling
     */
    function run() {
        global $ost;

        if(!$ost->getConfig()->isEmailPollingEnabled())
            return;

        //We require imap ext to fetch emails via IMAP/POP3
        //We check here just in case the extension gets disabled post email config...
        if(!function_exists('imap_open')) {
            $msg='osTicket requires PHP IMAP extension enabled for IMAP/POP3 email fetch to work!';
            $ost->logWarning('Mail Fetch Error', $msg);
            return;
        }

        //Hardcoded error control...
        $MAXERRORS = 5; //Max errors before we start delayed fetch attempts
        $TIMEOUT = 10; //Timeout in minutes after max errors is reached.

        $sql=' SELECT email_id, mail_errors FROM '.EMAIL_TABLE
            .' WHERE mail_active=1 '
            .'  AND (mail_errors<='.$MAXERRORS.' OR (TIME_TO_SEC(TIMEDIFF(NOW(), mail_lasterror))>'.($TIMEOUT*60).') )'
            .'  AND (mail_lastfetch IS NULL OR TIME_TO_SEC(TIMEDIFF(NOW(), mail_lastfetch))>mail_fetchfreq*60)'
            .' ORDER BY mail_lastfetch ASC';

        if (!($res=db_query($sql)) || !db_num_rows($res))
            return;  /* Failed query (get's logged) or nothing to do... */

        //Get max execution time so we can figure out how long we can fetch
        // take fetching emails.
        if (!($max_time = ini_get('max_execution_time')))
            $max_time = 300;

        //Start time
        $start_time = Misc::micro_time();
        while (list($emailId, $errors)=db_fetch_row($res)) {
            //Break if we're 80% into max execution time
            if ((Misc::micro_time()-$start_time) > ($max_time*0.80))
                break;

            $fetcher = new MailFetcher($emailId);
            if ($fetcher->connect()) {
                db_query('UPDATE '.EMAIL_TABLE.' SET mail_errors=0, mail_lastfetch=NOW() WHERE email_id='.db_input($emailId));
                $fetcher->fetchEmails();
                $fetcher->close();
            } else {
                db_query('UPDATE '.EMAIL_TABLE.' SET mail_errors=mail_errors+1, mail_lasterror=NOW() WHERE email_id='.db_input($emailId));
                if (++$errors>=$MAXERRORS) {
                    //We've reached the MAX consecutive errors...will attempt logins at delayed intervals
                    $msg="\nosTicket is having trouble fetching emails from the following mail account: \n".
                        "\nUser: ".$fetcher->getUsername().
                        "\nHost: ".$fetcher->getHost().
                        "\nError: ".$fetcher->getLastError().
                        "\n\n ".$errors.' consecutive errors. Maximum of '.$MAXERRORS. ' allowed'.
                        "\n\n This could be connection issues related to the mail server. Next delayed login attempt in approx. $TIMEOUT minutes";
                    $ost->alertAdmin('Mail Fetch Failure Alert', $msg, true);
                }
            }
        } //end while.
    }
}
?>
