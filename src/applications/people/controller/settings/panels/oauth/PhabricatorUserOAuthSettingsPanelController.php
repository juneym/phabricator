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

class PhabricatorUserOAuthSettingsPanelController
  extends PhabricatorUserSettingsPanelController {

  private $provider;

  public function setOAuthProvider(PhabricatorOAuthProvider $oauth_provider) {
    $this->provider = $oauth_provider;
    return $this;
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();
    $provider = $this->provider;

    $notice = null;

    $provider_name = $provider->getProviderName();
    $provider_key = $provider->getProviderKey();

    $oauth_info = id(new PhabricatorUserOAuthInfo())->loadOneWhere(
      'userID = %d AND oauthProvider = %s',
      $user->getID(),
      $provider->getProviderKey());

    $form = new AphrontFormView();
    $form
      ->setUser($user);

    $forms = array();
    $forms[] = $form;
    if (!$oauth_info) {
      $form
        ->appendChild(
          '<p class="aphront-form-instructions">There is currently no '.
          $provider_name.' account linked to your Phabricator account. You '.
          'can link an account, which will allow you to use it to log into '.
          'Phabricator.</p>');

      switch ($provider_key) {
        case PhabricatorOAuthProvider::PROVIDER_GITHUB:
          $form->appendChild(
            '<p class="aphront-form-instructions">Additionally, you must '.
            'link your Github account before Phabricator can access any '.
            'information about hosted repositories.</p>');
          break;
      }

      $auth_uri = $provider->getAuthURI();
      $client_id = $provider->getClientID();
      $redirect_uri = $provider->getRedirectURI();
      $minimum_scope  = $provider->getMinimumScope();

      $form
        ->setAction($auth_uri)
        ->setMethod('GET')
        ->addHiddenInput('redirect_uri', $redirect_uri)
        ->addHiddenInput('client_id', $client_id)
        ->addHiddenInput('scope', $minimum_scope);

      foreach ($provider->getExtraAuthParameters() as $key => $value) {
        $form->addHiddenInput($key, $value);
      }

      $form
        ->appendChild(
          id(new AphrontFormSubmitControl())
            ->setValue('Link '.$provider_name." Account \xC2\xBB"));
    } else {
      $form
        ->appendChild(
          '<p class="aphront-form-instructions">Your account is linked with '.
          'a '.$provider_name.' account. You may use your '.$provider_name.' '.
          'credentials to log into Phabricator.</p>')
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel($provider_name.' ID')
            ->setValue($oauth_info->getOAuthUID()))
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel($provider_name.' Name')
            ->setValue($oauth_info->getAccountName()))
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel($provider_name.' URI')
            ->setValue($oauth_info->getAccountURI()));

      if (!$provider->isProviderLinkPermanent()) {
        $unlink = 'Unlink '.$provider_name.' Account';
        $unlink_form = new AphrontFormView();
        $unlink_form
          ->setUser($user)
          ->appendChild(
            '<p class="aphront-form-instructions">You may unlink this account '.
            'from your '.$provider_name.' account. This will prevent you from '.
            'logging in with your '.$provider_name.' credentials.</p>')
          ->appendChild(
            id(new AphrontFormSubmitControl())
              ->addCancelButton('/oauth/'.$provider_key.'/unlink/', $unlink));
        $forms['Unlink Account'] = $unlink_form;
      }

      $expires = $oauth_info->getTokenExpires();
      if ($expires) {
        if ($expires <= time()) {
          $expires = "Expired";
        } else {
          $expires = phabricator_format_timestamp($expires);
        }
      } else {
        $expires = 'No Information Available';
      }

      $scope = $oauth_info->getTokenScope();
      if (!$scope) {
        $scope = 'No Information Available';
      }

      $status = $oauth_info->getTokenStatus();
      $status = PhabricatorUserOAuthInfo::getReadableTokenStatus($status);

      $token_form = new AphrontFormView();
      $token_form
        ->setUser($user)
        ->appendChild(
          '<p class="aphront-from-instructions">insert rap about tokens</p>')
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel('Token Status')
            ->setValue($status))
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel('Expires')
            ->setValue($expires))
        ->appendChild(
          id(new AphrontFormStaticControl())
            ->setLabel('Scope')
            ->setValue($scope));

      $forms['Account Token Information'] = $token_form;
    }

    $panel = new AphrontPanelView();
    $panel->setHeader($provider_name.' Account Settings');
    $panel->setWidth(AphrontPanelView::WIDTH_FORM);
    foreach ($forms as $name => $form) {
      if ($name) {
        $panel->appendChild('<br /><br /><h1>'.$name.'</h1>');
      }
      $panel->appendChild($form);
    }

    return id(new AphrontNullView())
      ->appendChild(
        array(
          $notice,
          $panel,
        ));
  }
}
