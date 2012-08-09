<?php

namespace Objects\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\HttpFoundation\Response;
use Objects\APIBundle\Controller\TwitterController;
use Objects\APIBundle\Controller\FacebookController;
use Objects\UserBundle\Entity\SocialAccounts;
use Objects\UserBundle\Entity\User;
use Objects\UserBundle\Form\UserSignUp;
use Objects\UserBundle\Form\UserSignUpPopUp;

class UserController extends Controller {

    /**
     * the main login action
     * @author Mahmoud
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function loginAction() {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        // get the login error if there is one
        if ($request->attributes->has(SecurityContext::AUTHENTICATION_ERROR)) {
            $error = $request->attributes->get(SecurityContext::AUTHENTICATION_ERROR);
        } else {
            $error = $session->get(SecurityContext::AUTHENTICATION_ERROR);
        }
        //check if it is an ajax request
        if ($request->isXmlHttpRequest()) {
            //return a pop up render
            return $this->render('ObjectsUserBundle:User:login_popup.html.twig', array(
                        // last username entered by the user
                        'last_username' => $session->get(SecurityContext::LAST_USERNAME),
                        'error' => $error,
                            ));
        }
        //return the main page
        return $this->render('ObjectsUserBundle:User:login.html.twig', array(
                    // last username entered by the user
                    'last_username' => $session->get(SecurityContext::LAST_USERNAME),
                    'error' => $error,
                        ));
    }

    /**
     * this funcion redirects the user to specific url
     * @author Mahmoud
     * @return \Symfony\Component\HttpFoundation\Response a redirect to a url
     */
    public function redirectUserAction() {
        //get the session object
        $session = $this->getRequest()->getSession();
        //check if we have a url to redirect to
        $rediretUrl = $session->get('redirectUrl', FALSE);
        if (!$rediretUrl) {
            //check if firewall redirected the user
            $rediretUrl = $session->get('_security.target_path');
            if (!$rediretUrl) {
                //redirect to home page
                $rediretUrl = '/';
            }
        } else {
            //remove the redirect url from the session
            $session->remove('redirectUrl');
        }
        return $this->redirect($rediretUrl);
    }

    /**
     * the signup action
     * the link to this page should not be visible for the logged in user
     * @author Mahmoud
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function signUpAction() {
        //check that a logged in user can not access this action
        if (TRUE === $this->get('security.context')->isGranted('ROLE_NOTACTIVE')) {
            //go to the home page
            return $this->redirect('/');
        }
        //get the request object
        $request = $this->getRequest();
        //create an emtpy user object
        $user = new User();
        //check if this is an ajax request
        if ($request->isXmlHttpRequest()) {
            //create a popup form
            $form = $this->createForm(new UserSignUpPopUp(), $user);
            //use the popup twig
            $view = 'ObjectsUserBundle:User:signup_popup.html.twig';
        } else {
            //create a signup form
            $form = $this->createForm(new UserSignUp(), $user);
            //use the signup page
            $view = 'ObjectsUserBundle:User:signup.html.twig';
        }
        //check if this is the user posted his data
        if ($request->getMethod() == 'POST') {
            //fill the form data from the request
            $form->bindRequest($request);
            //check if the form values are correct
            if ($form->isValid()) {
                //get the user object from the form
                $user = $form->getData();
                //user data are valid finish the signup process
                return $this->finishSignUp($user);
            }
        }
        return $this->render($view, array(
                    'form' => $form->createView()
                ));
    }

    /**
     * the edit action
     * @author Mahmoud
     * @param string $loginName the user login name to edit his profile
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editAction($loginName) {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        try {
            //try to find the requested user object
            $requestedUser = $em->getRepository('ObjectsUserBundle:User')->findOneByLoginName($loginName);
        } catch (\Exception $e) {
            //the user not found return 404 response
            throw $this->createNotFoundException('user not found');
        }
        //get the user object from the firewall
        $loggedInUser = $this->get('security.context')->getToken()->getUser();
        //check if the logged in user is the same as the requested one
        if ($loggedInUser->getId() != $requestedUser->getId()) {
            //not the same user as the logged in
            throw new AccessDeniedHttpException();
        }
        //get the translator object
        $translator = $this->get('translator');
        //get the user social accounts object
        $socialAccounts = $loggedInUser->getSocialAccounts();
        //initialize the success message
        $message = FALSE;
        //initialize the redirect flag
        $redirect = FALSE;
        //initialize the form validation groups array
        $formValidationGroups = array('edit');
        //initialize the old password to not required
        $oldPassword = FALSE;
        //initialize the change user name to false
        $changeUserName = FALSE;
        //check if the user is logged in from the login form
        if (FALSE === $this->get('security.context')->isGranted('IS_AUTHENTICATED_FULLY')) {
            //mark the old password as required
            $oldPassword = TRUE;
        }
        //check if the user is logged in from the login form
        if (TRUE === $this->get('security.context')->isGranted('ROLE_UPDATABLE_USERNAME')) {
            //make the user able to change his user name
            $changeUserName = TRUE;
        }
        //check if the old password is required
        if ($oldPassword) {
            //add the old password group to the form validation array
            $formValidationGroups [] = 'oldPassword';
        }
        //check if the user can change his user name
        if ($changeUserName) {
            //add the login name group to the form validation array
            $formValidationGroups [] = 'loginName';
        }
        //get the old user email
        $oldEmail = $loggedInUser->getEmail();
        //get the old user name
        $oldLoginName = $loggedInUser->getLoginName();
        //create a password form
        $formBuilder = $this->createFormBuilder($loggedInUser, array(
                    'validation_groups' => $formValidationGroups
                ))
                ->add('userPassword', 'repeated', array(
                    'type' => 'password',
                    'first_name' => "Password",
                    'second_name' => "RePassword",
                    'invalid_message' => "The passwords don't match",
                    'required' => false
                ))
                ->add('gender', 'choice', array(
                    'choices' => array('1' => $translator->trans('Male'), '0' => $translator->trans('Female')),
                    'required' => false,
                    'expanded' => true,
                    'multiple' => false
                ))
                ->add('dateOfBirth')
                ->add('firstName')
                ->add('lastName')
                ->add('countryCode', 'country', array('required' => false))
                ->add('email')
        ;
        //check if the old password is required
        if ($oldPassword) {
            //add the old password field
            $formBuilder->add('oldPassword', 'password');
        }
        //check if the user can change his user name
        if ($changeUserName) {
            //add the login name field
            $formBuilder->add('loginName');
        }
        //create the form
        $form = $formBuilder->getForm();
        //check if this is the user posted his data
        if ($request->getMethod() == 'POST') {
            //fill the form data from the request
            $form->bindRequest($request);
            //check if the form values are correct
            if ($form->isValid()) {
                //get the user object from the form
                $user = $form->getData();
                //check if we need to change the user to not active
                if ($user->getEmail() != $oldEmail && !$this->container->getParameter('auto_active')) {
                    //remove the role user
                    foreach ($user->getUserRoles() as $key => $roleObject) {
                        //check if this is the wanted role
                        if ($roleObject->getName() == 'ROLE_USER') {
                            //remove the role from the user
                            $user->getUserRoles()->remove($key);
                            //stop the search
                            break;
                        }
                    }
                    //get the not active role object
                    $role = $em->getRepository('ObjectsUserBundle:Role')->findOneByName('ROLE_NOTACTIVE');
                    //check if the user already has the role
                    if (!$user->getUserRoles()->contains($role)) {
                        //add the role to the user
                        $user->addRole($role);
                    }
                    //prepare the body of the email
                    $body = $this->renderView('ObjectsUserBundle:User:Emails\activate_email.txt.twig', array('user' => $user));
                    //prepare the message object
                    $message = \Swift_Message::newInstance()
                            ->setSubject($translator->trans('activate your account'))
                            ->setFrom($this->container->getParameter('mailer_user'))
                            ->setTo($user->getEmail())
                            ->setBody($body)
                    ;
                    //send the activation mail to the user
                    $this->get('mailer')->send($message);
                }
                //check if the user changed his login name
                if ($changeUserName && $oldLoginName != $user->getLoginName()) {
                    //remove the update user name role
                    foreach ($user->getUserRoles() as $key => $roleObject) {
                        //check if this is the wanted role
                        if ($roleObject->getName() == 'ROLE_UPDATABLE_USERNAME') {
                            //remove the role from the user
                            $user->getUserRoles()->remove($key);
                            //stop the search
                            break;
                        }
                    }
                    //redirect the user to remove the login name from the form and to correct the url and refresh his roles
                    $redirect = TRUE;
                }
                //set the password for the user if changed
                $user->setValidPassword();
                //save the data
                $em->flush();
                //check if the user set a valid old password
                if ($oldPassword) {
                    //redirect the user to remove the old password from the form
                    $redirect = TRUE;
                }
                //check if we need to redirect the user
                if ($redirect) {
                    //set the success flash
                    $session->setFlash('success', $translator->trans('Done'));
                    //make the user fully authenticated and refresh his roles
                    try {
                        // create the authentication token
                        $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                        // give it to the security context
                        $this->get('security.context')->setToken($token);
                    } catch (\Exception $e) {
                        //can not reload the user object log out the user
                        $this->get('security.context')->setToken(null);
                        //invalidate the current user session
                        $this->getRequest()->getSession()->invalidate();
                        //redirect to the login page
                        return $this->redirect($this->generateUrl('login', array(), TRUE));
                    }
                    //redirect the user
                    return $this->redirect($this->generateUrl('user_edit', array('loginName' => $user->getLoginName())));
                }
                //set the success message
                $message = 'Done';
            }
        }
        return $this->render('ObjectsUserBundle:User:edit.html.twig', array(
                    'form' => $form->createView(),
                    'loginName' => $loggedInUser->getLoginName(),
                    'oldPassword' => $oldPassword,
                    'changeUserName' => $changeUserName,
                    'message' => $message,
                    'socialAccounts' => $socialAccounts
                ));
    }

    /**
     * this action will link the user account to his twitter account
     * @author Mahmoud
     */
    public function twitterLinkAction() {
        //get the user object from the firewall
        $user = $this->get('security.context')->getToken()->getUser();
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the oauth token from the session
        $oauth_token = $session->get('oauth_token', FALSE);
        //get the oauth token secret from the session
        $oauth_token_secret = $session->get('oauth_token_secret', FALSE);
        //get the twtiter id from the session
        $twitterId = $session->get('twitterId', FALSE);
        //get the screen name from the session
        $screen_name = $session->get('screen_name', FALSE);
        //check if we got twitter data
        if ($oauth_token && $oauth_token_secret && $twitterId && $screen_name) {
            //get the entity manager
            $em = $this->getDoctrine()->getEntityManager();
            //get the user social account object
            $socialAccounts = $user->getSocialAccounts();
            //check if the user does not have a social account object
            if (!$socialAccounts) {
                //create new social account for the user
                $socialAccounts = new SocialAccounts();
                $socialAccounts->setUser($user);
                $user->setSocialAccounts($socialAccounts);
                $em->persist($socialAccounts);
            }
            //set the user twitter data
            $socialAccounts->setTwitterId($twitterId);
            $socialAccounts->setOauthToken($oauth_token);
            $socialAccounts->setOauthTokenSecret($oauth_token_secret);
            $socialAccounts->setScreenName($screen_name);
            //save the data for the user
            $em->flush();
            //set the success flag in the session
            $session->setFlash('success', $this->get('translator')->trans('Done'));
        }
        //twitter data not found go to the signup page
        return $this->redirect($this->generateUrl('user_edit', array('loginName' => $user->getLoginName())));
    }

    /**
     * this function is used to signup or login the user from twitter
     * @author Mahmoud 
     */
    public function twitterEnterAction() {
        //check that a logged in user can not access this action
        if (TRUE === $this->get('security.context')->isGranted('ROLE_NOTACTIVE')) {
            //go to the home page
            return $this->redirect('/');
        }
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the oauth token from the session
        $oauth_token = $session->get('oauth_token', FALSE);
        //get the oauth token secret from the session
        $oauth_token_secret = $session->get('oauth_token_secret', FALSE);
        //get the twtiter id from the session
        $twitterId = $session->get('twitterId', FALSE);
        //get the screen name from the session
        $screen_name = $session->get('screen_name', FALSE);
        //check if we got twitter data
        if ($oauth_token && $oauth_token_secret && $twitterId && $screen_name) {
            //get the entity manager
            $em = $this->getDoctrine()->getEntityManager();
            //check if the user twitter id is in our database
            $socialAccounts = $em->getRepository('ObjectsUserBundle:SocialAccounts')->findOneBy(array('twitterId' => $twitterId));
            //check if we found the user
            if ($socialAccounts) {
                //user found check if the access tokens have changed
                if ($socialAccounts->getOauthToken() != $oauth_token) {
                    //tokens changed update the tokens
                    $socialAccounts->setOauthToken($oauth_token);
                    $socialAccounts->setOauthTokenSecret($oauth_token_secret);
                    //save the new access tokens
                    $em->flush();
                }
                //get the user object
                $user = $socialAccounts->getUser();
                //try to login the user
                try {
                    // create the authentication token
                    $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                    // give it to the security context
                    $this->get('security.context')->setToken($token);
                    //redirect the user
                    return $this->redirectUserAction();
                } catch (\Exception $e) {
                    //can not reload the user object log out the user
                    $this->get('security.context')->setToken(null);
                    //invalidate the current user session
                    $this->getRequest()->getSession()->invalidate();
                    //redirect to the login page
                    return $this->redirect($this->generateUrl('login', array(), TRUE));
                }
            }
            //create a new user object
            $user = new User();
            //create an email form
            $form = $this->createFormBuilder($user, array(
                        'validation_groups' => array('email')
                    ))
                    ->add('email', 'repeated', array(
                        'type' => 'email',
                        'first_name' => 'Email',
                        'second_name' => 'ReEmail',
                        'invalid_message' => "The emails don't match",
                    ))
                    ->getForm();
            //check if this is the user posted his data
            if ($request->getMethod() == 'POST') {
                //fill the form data from the request
                $form->bindRequest($request);
                //check if the form values are correct
                if ($form->isValid()) {
                    //get the container object
                    $container = $this->container;
                    //get the user object from the form
                    $user = $form->getData();
                    //request additional user data from twitter
                    $content = TwitterController::getCredentials($container->getParameter('consumer_key'), $container->getParameter('consumer_secret'), $oauth_token, $oauth_token_secret);
                    //check if we got the user data
                    if ($content) {
                        //get the name parts
                        $name = explode(' ', $content->name);
                        if (!empty($name[0])) {
                            $user->setFirstName($name[0]);
                        }
                        if (!empty($name[1])) {
                            $user->setLastName($name[1]);
                        }
                        //set the additional data
                        $user->setUrl($content->url);
                        //set the about text
                        $user->setAbout($content->description);
                        //try to download the user image from twitter
                        $image = TwitterController::downloadTwitterImage($content->profile_image_url, $user->getUploadRootDir());
                        //check if we got an image
                        if ($image) {
                            //add the image to the user
                            $user->setImage($image);
                        }
                    }
                    //create social accounts object
                    $socialAccounts = new SocialAccounts();
                    $socialAccounts->setOauthToken($oauth_token);
                    $socialAccounts->setOauthTokenSecret($oauth_token_secret);
                    $socialAccounts->setTwitterId($twitterId);
                    $socialAccounts->setScreenName($screen_name);
                    $socialAccounts->setUser($user);
                    //set the user twitter info
                    $user->setSocialAccounts($socialAccounts);
                    //set a valid login name
                    $user->setLoginName($this->suggestLoginName($screen_name));
                    //user data are valid finish the signup process
                    return $this->finishSignUp($user);
                }
            }
            return $this->render('ObjectsUserBundle:User:twitter_signup.html.twig', array(
                        'form' => $form->createView()
                    ));
        } else {
            //twitter data not found go to the signup page
            return $this->redirect($this->generateUrl('signup', array(), TRUE));
        }
    }

    /**
     * action handle login/linking/signup via facebook
     * this action is called when facebook dialaog redirect to it
     * @author Mirehan
     * @todo enable signup post on the user wall
     */
    public function facebookAction() {
        //check that a logged in user can not access this action
        if (TRUE === $this->get('security.context')->isGranted('ROLE_NOTACTIVE')) {
            //go to the home page
            return $this->redirect('/');
        }
        $request = $this->getRequest();
        $session = $request->getSession();
        //get page url that the facebook button in
        $returnURL = $session->get('currentURL', FALSE);
        if (!$returnURL) {
            $returnURL = '/';
        }
        //user access Token
        $shortLive_access_token = $session->get('facebook_short_live_access_token', FALSE);
        //facebook User Object
        $faceUser = $session->get('facebook_user', FALSE);
        // something went wrong
        $facebookError = $session->get('facebook_error', FALSE);

        if ($facebookError || !$faceUser || !$shortLive_access_token) {
            return $this->redirect('/');
        }

        //generate long-live facebook access token access token and expiration date
        $longLive_accessToken = FacebookController::getLongLiveFaceboockAccessToken($this->container->getParameter('fb_app_id'), $this->container->getParameter('fb_app_secret'), $shortLive_access_token);

        $em = $this->getDoctrine()->getEntityManager();

        //check if the user facebook id is in our database
        $socialAccounts = $em->getRepository('ObjectsUserBundle:SocialAccounts')->findOneBy(array('facebookId' => $faceUser->id));

        if ($socialAccounts) {
            //update long-live facebook access token
            $socialAccounts->setAccessToken($longLive_accessToken['access_token']);
            $socialAccounts->setFbTokenExpireDate(new \DateTime(date('Y-m-d', time() + $longLive_accessToken['expires'])));

            $em->flush();
            //get the user object
            $user = $socialAccounts->getUser();
            //try to login the user
            try {
                // create the authentication token
                $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                // give it to the security context
                $this->get('security.context')->setToken($token);
                //redirect the user
                return $this->redirectUserAction();
            } catch (\Exception $e) {
                //can not reload the user object log out the user
                $this->get('security.context')->setToken(null);
                //invalidate the current user session
                $this->getRequest()->getSession()->invalidate();
                //redirect to the login page
                return $this->redirect($this->generateUrl('login', array(), TRUE));
            }
        } else {
            /**
             *
             * the account of the same email as facebook account maybe exist but not linked so we will link it 
             * and directly logging the user
             * if the account is not active we automatically activate it
             * else will create the account ,sign up the user
             * 
             * */
            $userRepository = $this->getDoctrine()->getRepository('ObjectsUserBundle:User');
            $roleRepository = $this->getDoctrine()->getRepository('ObjectsUserBundle:Role');
            $user = $userRepository->findOneByEmail($faceUser->email);
            //if user exist only add facebook account to social accounts record if user have one
            //if not create new record
            if ($user) {
                $socialAccounts = $user->getSocialAccounts();
                if (empty($socialAccounts)) {
                    $socialAccounts = new SocialAccounts();
                    $socialAccounts->setUser($user);
                }
                $socialAccounts->setFacebookId($faceUser->id);
                $socialAccounts->setAccessToken($longLive_accessToken['access_token']);
                $socialAccounts->setFbTokenExpireDate(new \DateTime(date('Y-m-d', time() + $longLive_accessToken['expires'])));
                $user->setSocialAccounts($socialAccounts);

                //activate user if is not activated
                //get object of notactive Role
                $notActiveRole = $roleRepository->findOneByName('ROLE_NOTACTIVE');
                if ($user->getUserRoles()->contains($notActiveRole)) {
                    //get a user role object
                    $userRole = $roleRepository->findOneByName('ROLE_USER');
                    //remove notactive Role from user in exist
                    $user->getUserRoles()->removeElement($notActiveRole);

                    $user->getUserRoles()->add($userRole);

                    $fbLinkeDAndActivatedmessage = $this->get('translator')->trans('Your Facebook account was successfully Linked to your account') . ' ' . $this->get('translator')->trans('your account is now active');
                    //set flash message to tell user that him/her account has been successfully activated
                    $session->setFlash('notice', $fbLinkeDAndActivatedmessage);
                } else {
                    $fbLinkeDmessage = $this->get('translator')->trans('Your Facebook account was successfully Linked to your account');
                    //set flash message to tell user that him/her account has been successfully linked
                    $session->setFlash('notice', $fbLinkeDmessage);
                }
                $em->persist($user);
                $em->flush();

                //try to login the user
                try {
                    // create the authentication token
                    $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                    // give it to the security context
                    $this->get('security.context')->setToken($token);
                    //redirect the user
                    return $this->redirectUserAction();
                } catch (\Exception $e) {
                    //can not reload the user object log out the user
                    $this->get('security.context')->setToken(null);
                    //invalidate the current user session
                    $this->getRequest()->getSession()->invalidate();
                    //redirect to the login page
                    return $this->redirect($this->generateUrl('login', array(), TRUE));
                }
            } else {

                //user sign up
                $user = new User();
                $user->setEmail($faceUser->email);
                //set a valid login name
                $user->setLoginName($this->suggestLoginName(strtolower($faceUser->name)));
                $user->setFirstName($faceUser->first_name);
                $user->setLastName($faceUser->last_name);
                if ($faceUser->gender == 'female') {
                    $user->setGender(0);
                } else {
                    $user->setGender(1);
                }
                //try to download the user image from facebook
                $image = FacebookController::downloadAccountImage($faceUser->id, $user->getUploadRootDir());
                //check if we got an image
                if ($image) {
                    //add the image to the user
                    $user->setImage($image);
                }

                //create $socialAccounts object and set facebook account
                $socialAccounts = new SocialAccounts();
                $socialAccounts->setFacebookId($faceUser->id);
                $socialAccounts->setAccessToken($longLive_accessToken['access_token']);
                $socialAccounts->setFbTokenExpireDate(new \DateTime(date('Y-m-d', time() + $longLive_accessToken['expires'])));
                $socialAccounts->setUser($user);
                $user->setSocialAccounts($socialAccounts);
                $translator = $this->get('translator');
                
                //TODO use
                //send feed to user profile with sign up
                //FacebookController::postOnUserWallAndFeedAction($faceUser->id, $longLive_accessToken['access_token'], $translator->trans('I have new account on this cool site'), $translator->trans('PROJECT_NAME'), $translator->trans('SITE_DESCRIPTION'), 'PROJECT_ORIGINAL_URL', 'SITE_PICTURE');

                //set flash message to tell user that him/her account has been successfully activated
                $session->setFlash('notice', $translator->trans('your account is now active'));
                //user data are valid finish the signup process
                return $this->finishSignUp($user, TRUE);
            }
        }
    }

    /**
     * this action will link the user account to the facebook account
     * @author Mirehan & Mahmoud
     */
    public function facebookLinkAction() {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //user access Token
        $shortLive_access_token = $session->get('facebook_short_live_access_token', FALSE);
        //facebook User Object
        $faceUser = $session->get('facebook_user', FALSE);
        // something went wrong
        $facebookError = $session->get('facebook_error', FALSE);
        //check if we have no errors
        if ($facebookError || !$faceUser || !$shortLive_access_token) {
            return $this->redirect('/');
        }

        //generate long-live facebook access token access token and expiration date
        $longLive_accessToken = FacebookController::getLongLiveFaceboockAccessToken($this->container->getParameter('fb_app_id'), $this->container->getParameter('fb_app_secret'), $shortLive_access_token);

        $em = $this->getDoctrine()->getEntityManager();

        $roleRepository = $this->getDoctrine()->getRepository('ObjectsUserBundle:Role');
        $user = $this->get('security.context')->getToken()->getUser();
        $socialAccounts = $user->getSocialAccounts();
        if (empty($socialAccounts)) {
            $socialAccounts = new SocialAccounts();
            $socialAccounts->setUser($user);
            $em->persist($socialAccounts);
        }
        $socialAccounts->setFacebookId($faceUser->id);
        $socialAccounts->setAccessToken($longLive_accessToken['access_token']);
        $socialAccounts->setFbTokenExpireDate(new \DateTime(date('Y-m-d', time() + $longLive_accessToken['expires'])));
        $user->setSocialAccounts($socialAccounts);

        //activate user if is not activated
        //get object of notactive Role
        $notActiveRole = $roleRepository->findOneByName('ROLE_NOTACTIVE');
        if ($user->getUserRoles()->contains($notActiveRole) && $user->getEmail() == $faceUser->email) {
            //get a user role object
            $userRole = $roleRepository->findOneByName('ROLE_USER');
            //remove notactive Role from user in exist
            $user->getUserRoles()->removeElement($notActiveRole);

            $user->getUserRoles()->add($userRole);

            $fbLinkeDAndActivatedmessage = $this->get('translator')->trans('Your Facebook account was successfully Linked to your account') . ' ' . $this->get('translator')->trans('your account is now active');
            //set flash message to tell user that him/her account has been successfully activated
            $session->setFlash('notice', $fbLinkeDAndActivatedmessage);
        } else {
            $fbLinkeDmessage = $this->get('translator')->trans('Your Facebook account was successfully Linked to your account');
            //set flash message to tell user that him/her account has been successfully linked
            $session->setFlash('notice', $fbLinkeDmessage);
        }
        $em->flush();

        return $this->redirect($this->generateUrl('user_edit', array('loginName' => $user->getLoginName())));
    }

    /**
     * this action will unlink the user social data data
     * @author Mahmoud
     * @param string $social twitter | facebook
     */
    public function socialUnlinkAction($social) {
        //get the logged in user object
        $user = $this->get('security.context')->getToken()->getUser();
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        //get the user social account object
        $socialAccounts = $user->getSocialAccounts();
        if ($social == 'facebook') {
            //unlink the facebook account data
            $socialAccounts->unlinkFacebook();
        }
        if ($social == 'twitter') {
            //unlink the facebook account data
            $socialAccounts->unlinkTwitter();
        }
        //check if we still need the object
        if(!$socialAccounts->isNeeded()){
            //remove the object
            $em->remove($socialAccounts);
        }
        //save the changes
        $em->flush();
        //set a success flag in the session
        $this->getRequest()->getSession()->setFlash('success', $this->get('translator')->trans('Done'));
        //redirect the user to the edit page
        return $this->redirect($this->generateUrl('user_edit', array('loginName' => $user->getLoginName())));
    }

    /**
     * this function is used to save the user data in the database and then send him a welcome message
     * and then try to login the user and redirect him to homepage or login page on fail
     * @author Mahmoud
     * @param \Objects\UserBundle\Entity\User $user
     * @param boolean $active if this flag is set the user will be treated as already active
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function finishSignUp($user, $active = FALSE) {
        //check if the user is already active
        if (!$active) {
            //get the activation configurations
            $active = $this->container->getParameter('auto_active');
        }
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        //add the new user to the entity manager
        $em->persist($user);
        //prepare the body of the email
        $body = $this->renderView('ObjectsUserBundle:User:Emails\welcome_to_site.txt.twig', array(
            'user' => $user,
            'password' => $user->getUserPassword(),
            'active' => $active
                ));
        //check if the user should be active by email or auto activated
        if ($active) {
            //auto active user
            $roleName = 'ROLE_USER';
        } else {
            //user need to activate from email
            $roleName = 'ROLE_NOTACTIVE';
        }
        //get the role repo
        $roleRepository = $em->getRepository('ObjectsUserBundle:Role');
        //get a user role object
        $role = $roleRepository->findOneByName($roleName);
        //get a update userName role object
        $roleUpdateUserName = $roleRepository->findOneByName('ROLE_UPDATABLE_USERNAME');
        //set user roles
        $user->addRole($role);
        $user->addRole($roleUpdateUserName);
        //store the object in the database
        $em->flush();
        //prepare the message object
        $message = \Swift_Message::newInstance()
                ->setSubject($this->get('translator')->trans('welcome'))
                ->setFrom($this->container->getParameter('mailer_user'))
                ->setTo($user->getEmail())
                ->setBody($body)
        ;
        //send the email
        $this->get('mailer')->send($message);
        //try to login the user
        try {
            // create the authentication token
            $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
            // give it to the security context
            $this->get('security.context')->setToken($token);
        } catch (\Exception $e) {
            //can not reload the user object log out the user
            $this->get('security.context')->setToken(null);
            //invalidate the current user session
            $this->getRequest()->getSession()->invalidate();
            //redirect to the login page
            return $this->redirect($this->generateUrl('login', array(), TRUE));
        }
        //go to the home page
        return $this->redirect('/');
    }

    /**
     * this action will activate the user account and redirect him to the home page
     * after setting either success flag or error flag
     * @author Mahmoud
     * @param string $confirmationCode
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function activationAction($confirmationCode) {
        //get the user object from the firewall
        $user = $this->get('security.context')->getToken()->getUser();
        //get the session object
        $session = $this->getRequest()->getSession();
        //get the translator object
        $translator = $this->get('translator');
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        //get a user role object
        $roleUser = $em->getRepository('ObjectsUserBundle:Role')->findOneByName('ROLE_USER');
        //check if the user is already active (the user might visit the link twice)
        if ($user->getUserRoles()->contains($roleUser)) {
            //set a notice flag
            $session->setFlash('notice', $translator->trans('nothing to do'));
        } else {
            //check if the confirmation code is correct
            if ($user->getConfirmationCode() == $confirmationCode) {
                //get the current user roles
                $userRoles = $user->getUserRoles();
                //try to get the not active role
                foreach ($userRoles as $key => $userRole) {
                    //check if this role is the not active role
                    if ($userRole->getName() == 'ROLE_NOTACTIVE') {
                        //remove the not active role
                        $userRoles->remove($key);
                        //end the search
                        break;
                    }
                }
                //add the user role
                $user->addRole($roleUser);
                //save the new role for the user
                $em->flush();
                //set a success flag
                $session->setFlash('success', $translator->trans('your account is now active'));
                //try to refresh the user object roles in the firewall session
                try {
                    // create the authentication token
                    $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                    // give it to the security context
                    $this->get('security.context')->setToken($token);
                } catch (\Exception $e) {
                    //can not reload the user object log out the user
                    $this->get('security.context')->setToken(null);
                    //invalidate the current user session
                    $this->getRequest()->getSession()->invalidate();
                    //redirect to the login page
                    return $this->redirect($this->generateUrl('login', array(), TRUE));
                }
            } else {
                //set an error flag
                $session->setFlash('error', $translator->trans('invalid confirmation code'));
            }
        }
        //go to the home page
        return $this->redirect('/');
    }

    /**
     * forgot your password action
     * this function gets the user email and sends him email to let him change his password
     * @author mahmoud
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function forgotPasswordAction() {
        //check that a logged in user can not access this action
        if (TRUE === $this->get('security.context')->isGranted('ROLE_NOTACTIVE')) {
            return $this->redirect('/');
        }
        //get the request object
        $request = $this->getRequest();
        //prepare the form validation constrains
        $collectionConstraint = new Collection(array(
                    'email' => new Email()
                ));
        //create the form
        $form = $this->createFormBuilder(null, array(
                    'validation_constraint' => $collectionConstraint,
                ))->add('email', 'email')
                ->getForm();
        //initialze the error string
        $error = FALSE;
        //initialze the success string
        $success = FALSE;
        //check if form is posted
        if ($request->getMethod() == 'POST') {
            //bind the user data to the form
            $form->bindRequest($request);
            //check if form is valid
            if ($form->isValid()) {
                //get the translator object
                $translator = $this->get('translator');
                //get the form data
                $data = $form->getData();
                //get the email
                $email = $data['email'];
                //search for the user with the entered email
                $user = $this->getDoctrine()->getRepository('ObjectsUserBundle:User')->findOneBy(array('email' => $email));
                //check if we found the user
                if ($user) {
                    //set a new token for the user
                    $user->setConfirmationCode(md5(uniqid(rand())));
                    //save the new user token into database
                    $this->getDoctrine()->getEntityManager()->flush();
                    //prepare the body of the email
                    $body = $this->renderView('ObjectsUserBundle:User:Emails\forgot_your_password.txt.twig', array('user' => $user));
                    //prepare the message object
                    $message = \Swift_Message::newInstance()
                            ->setSubject($this->get('translator')->trans('forgot your password'))
                            ->setFrom($this->container->getParameter('mailer_user'))
                            ->setTo($user->getEmail())
                            ->setBody($body)
                    ;
                    //send the email
                    $this->get('mailer')->send($message);
                    //set the success message
                    $success = $translator->trans('done please check your email');
                } else {
                    //set the error message
                    $error = $translator->trans('the entered email was not found');
                }
            }
        }
        return $this->render('ObjectsUserBundle:User:forgot_password.html.twig', array(
                    'form' => $form->createView(),
                    'error' => $error,
                    'success' => $success
                ));
    }

    /**
     * the change of password page
     * @author mahmoud
     * @param string|NULL $confirmationCode the token sent to the user email
     * @param string|NULL $email the user email
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function changePasswordAction($confirmationCode = NULL, $email = NULL) {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the translator object
        $translator = $this->get('translator');
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        //the success of login flag used to generate corrcet submit route for the form
        $loginSuccess = FALSE;
        //check if the user came from the email link
        if ($confirmationCode && $email) {
            //try to get the user from the database
            $user = $em->getRepository('ObjectsUserBundle:User')->findoneBy(array('email' => $email, 'confirmationCode' => $confirmationCode));
            //check if we found the user
            if ($user) {
                //try to login the user
                try {
                    // create the authentication token
                    $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                    // give it to the security context
                    $this->get('security.context')->setToken($token);
                    //check if the user is active
                    if (FALSE === $this->get('security.context')->isGranted('ROLE_USER')) {
                        //activate the user if not active
                        $this->activationAction($confirmationCode);
                        //clear the flashes set by the activation action
                        $session->clearFlashes();
                    }
                    //set the login success flag
                    $loginSuccess = TRUE;
                } catch (\Exception $e) {
                    
                }
            } else {
                //set an error flag
                $session->setFlash('error', $translator->trans('invalid email or confirmation code'));
                //go to home page
                return $this->redirect('/');
            }
        } else {
            //check if the user is logged in from the login form
            if (FALSE === $this->get('security.context')->isGranted('IS_AUTHENTICATED_FULLY')) {
                //set the redirect url to the login action
                $session->set('redirectUrl', $this->generateUrl('change_password', array(), TRUE));
                //require the login from the user
                return $this->redirect($this->generateUrl('login', array(), TRUE));
            } else {
                //get the user object from the firewall
                $user = $this->get('security.context')->getToken()->getUser();
                //set the login success flag
                $loginSuccess = TRUE;
            }
        }
        //create a password form
        $form = $this->createFormBuilder($user, array(
                    'validation_groups' => array('password')
                ))
                ->add('userPassword', 'repeated', array(
                    'type' => 'password',
                    'first_name' => "Password",
                    'second_name' => "RePassword",
                    'invalid_message' => "The passwords don't match",
                ))
                ->getForm();
        //check if form is posted
        if ($request->getMethod() == 'POST') {
            //bind the user data to the form
            $form->bindRequest($request);
            //check if form is valid
            if ($form->isValid()) {
                //set the password for the user
                $user->setValidPassword();
                //save the new hashed password
                $em->flush();
                //set the success flag
                $session->setFlash('success', $translator->trans('password changed'));
                //go to home page
                return $this->redirect('/');
            }
        }
        return $this->render('ObjectsUserBundle:User:change_password.html.twig', array(
                    'form' => $form->createView(),
                    'loginSuccess' => $loginSuccess,
                    'user' => $user
                ));
    }

    /**
     * this action will give the user the ability to delete his account
     * it will not actually delete the account it will simply disable it
     * @author Mahmoud
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteAccountAction() {
        //get the request object
        $request = $this->getRequest();
        //check if form is posted
        if ($request->getMethod() == 'POST') {
            //get the user object from the firewall
            $user = $this->get('security.context')->getToken()->getUser();
            //set the delete flag
            $user->setEnabled(FALSE);
            //save the delete flag
            $this->getDoctrine()->getEntityManager()->flush();
            //go to home page
            return $this->redirect($this->generateUrl('logout', array(), TRUE));
        }
        return $this->render('ObjectsUserBundle:User:delete_account.html.twig');
    }

    /**
     * this function will check the login name againest the database if the name
     * does not exist the function will return the name otherwise it will try to return
     * a valid login Name
     * @author Alshimaa edited by Mahmoud
     * @param string $loginName
     * @return string a valid login name to use
     */
    private function suggestLoginName($loginName) {
        //get the entity manager
        $em = $this->getDoctrine()->getEntityManager();
        //get the user repo
        $userRepository = $em->getRepository('ObjectsUserBundle:User');
        //try to check if the given name does not exist
        $user = $userRepository->findOneByLoginName($loginName);
        if (!$user) {
            //valid login name
            return $loginName;
        }
        //get a valid one from the database
        return $userRepository->getValidLoginName($loginName);
    }

}
