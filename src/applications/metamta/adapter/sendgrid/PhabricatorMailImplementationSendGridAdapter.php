<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Mail adapter that uses SendGrid's web API to deliver email.
 */
class PhabricatorMailImplementationSendGridAdapter
  extends PhabricatorMailImplementationAdapter {

  private $params = array();

  public function setFrom($email, $name = '') {
    $this->params['from'] = $email;
    $this->params['from-name'] = $name;
    return $this;
  }

  public function addReplyTo($email, $name = '') {
    if (empty($this->params['reply-to'])) {
      $this->params['reply-to'] = array();
    }
    $this->params['reply-to'][] = array(
      'email' => $email,
      'name'  => $name,
    );
    return $this;
  }

  public function addTos(array $emails) {
    foreach ($emails as $email) {
      $this->params['tos'][] = $email;
    }
    return $this;
  }

  public function addCCs(array $emails) {
    foreach ($emails as $email) {
      $this->params['ccs'][] = $email;
    }
    return $this;
  }

  public function addHeader($header_name, $header_value) {
    $this->params['headers'][] = array($header_name, $header_value);
    return $this;
  }

  public function setBody($body) {
    $this->params['body'] = $body;
    return $this;
  }

  public function setSubject($subject) {
    $this->params['subject'] = $subject;
    return $this;
  }

  public function setIsHTML($is_html) {
    $this->params['is-html'] = $is_html;
    return $this;
  }

  public function supportsMessageIDHeader() {
    return false;
  }

  public function send() {

    $user = PhabricatorEnv::getEnvConfig('sendgrid.api-user');
    $key  = PhabricatorEnv::getEnvConfig('sendgrid.api-key');

    if (!$user || !$key) {
      throw new Exception(
        "Configure 'sendgrid.api-user' and 'sendgrid.api-key' to use ".
        "SendGrid for mail delivery.");
    }

    $params = array();

    $ii = 0;
    foreach (idx($this->params, 'tos', array()) as $to) {
      $params['to['.($ii++).']'] = $to;
    }

    $params['subject'] = idx($this->params, 'subject');
    if (idx($this->params, 'is-html')) {
      $params['html'] = idx($this->params, 'body');
    } else {
      $params['text'] = idx($this->params, 'body');
    }

    $params['from'] = idx($this->params, 'from');
    if (idx($this->params['from-name'])) {
      $params['fromname'] = idx($this->params, 'fromname');
    }

    if (idx($this->params, 'replyto')) {
      $replyto = $this->params['replyto'];

      // Pick off the email part, no support for the name part in this API.
      $params['replyto'] = $replyto[0];
    }

    $headers = idx($this->params, 'headers', array());

    // See SendGrid Support Ticket #29390; there's no explicit REST API support
    // for CC right now but it works if you add a generic "Cc" header.
    //
    // SendGrid said this is supported:
    //   "You can use CC as you are trying to do there [by adding a generic
    //    header]. It is supported despite our limited documentation to this
    //    effect, I am glad you were able to figure it out regardless. ..."
    if (idx($this->params, 'ccs')) {
      $headers[] = array('Cc', implode(', ', $this->params['ccs']));
    }

    if ($headers) {
      // Convert to dictionary.
      $headers = ipull($headers, 1, 0);
      $headers = json_encode($headers);
      $params['headers'] = $headers;
    }

    $params['api_user'] = $user;
    $params['api_key'] = $key;

    $future = new HTTPSFuture(
      'https://sendgrid.com/api/mail.send.json',
      $params);

    list($code, $body) = $future->resolve();

    if ($code !== 200) {
      throw new Exception("REST API call failed with HTTP code {$code}.");
    }

    $response = json_decode($body, true);
    if (!is_array($response)) {
      throw new Exception("Failed to JSON decode response: {$body}");
    }

    if ($response['message'] !== 'success') {
      $errors = implode(";", $response['errors']);
      throw new Exception("Request failed with errors: {$errors}.");
    }

    return true;
  }

}
