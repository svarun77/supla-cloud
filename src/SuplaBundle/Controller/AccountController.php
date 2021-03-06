<?php
/*
 src/SuplaBundle/Controller/AccountController.php

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SuplaBundle\Entity\IODeviceChannel;
use SuplaBundle\Enums\ScheduleAction;
use SuplaBundle\Form\Model\ChangePassword;
use SuplaBundle\Form\Model\Registration;
use SuplaBundle\Form\Model\ResetPassword;
use SuplaBundle\Form\Type\RegistrationType;
use SuplaBundle\Form\Type\ResetPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @Route("/account")
 */
class AccountController extends Controller {

    /**
     * @Route("/register", name="_account_register")
     */
    public function registerAction(Request $request) {
        $registration = new Registration();
        $form = $this->createForm(RegistrationType::class, $registration, [
            'action' => $this->generateUrl('_account_create_here'),
            'validation_groups' => ['_registration'],
        ]);

        return $this->render(
            'SuplaBundle:Account:register.html.twig',
            ['form' => $form->createView(),
                'locale' => $request->getLocale(),
            ]
        );
    }

    /**
     * @Route("/checkemail", name="_account_checkemail")
     */
    public function checkEmailAction() {
        $email = $this->container->get('session')->get('_registration_email');
        $this->container->get('session')->remove('_registration_email');

        if ($email === null) {
            return $this->redirectToRoute("_auth_login");
        }

        return $this->render(
            'SuplaBundle:Account:checkemail.html.twig',
            ['email' => $email]
        );
    }

    /**
     * @Route("/confirmemail/{token}", name="_account_confirmemail")
     */
    public function confirmEmailAction($token) {
        $user_manager = $this->get('user_manager');

        if (($user = $user_manager->confirm($token)) !== null) {
            $mailer = $this->get('supla_mailer');
            $mailer->sendActivationEmailMessage($user);

            $this->get('session')->getFlashBag()->add('success', [
                'title' => 'Success',
                'message' => 'Account has been activated. You can Sign In now.',
            ]);
        } else {
            $this->get('session')->getFlashBag()->add('error', ['title' => 'Error', 'message' => 'Token does not exist']);
        }

        return $this->redirectToRoute("_auth_login");
    }

    /**
     * @Route("/create_here", name="_account_create_here")
     */
    public function createActionHere(Request $request) {

        $form = $this->createForm(RegistrationType::class, new Registration(), ['language' => $request->getLocale()]);

        $form->handleRequest($request);

        $sl = $this->get('server_list');
        $remote_server= '';

        if ($form->isValid()) {
            $username = $form->getData()->getUser()->getUsername();

            for ($n = 0; $n < 4; $n++) {
                $exists = $sl->userExists($username, $remote_server);

                if ($exists === false) {
                    usleep(1000000);
                } else {
                    break;
                }
            }
        } else {
            $exists = false;
        }

        if ($exists === null) {
            $mailer = $this->get('supla_mailer');
            $mailer->sendServiceUnavailableMessage('createAction - remote server: '.$remote_server);
            
            return $this->redirectToRoute("_temp_unavailable");
        } elseif ($exists === true) {
            $translator = $this->get('translator');
            $form->get('user')->get('email')->addError(new FormError($translator->trans('Email already exists', [], 'validators')));
        }

        if ($exists === false
            && $form->isValid()
        ) {
            /** @var Registration $registration */
            $registration = $form->getData();
            $user_manager = $this->get('user_manager');
            $user = $registration->getUser();
            $user_manager->create($user);

            $mailer = $this->get('supla_mailer');
            $mailer->sendConfirmationEmailMessage($user);

            $this->container->get('session')->set('_registration_email', $user->getEmail());

            return $this->redirectToRoute("_account_checkemail");
        }

        return $this->render(
            'SuplaBundle:Account:register.html.twig',
            ['form_ca' => $form->createView(),
                'locale' => $request->getLocale(),
            ]
        );
    }

    /**
     * @Route("/create_here/{locale}", name="_account_create_here_lc")
     */
    public function createActionHereLC(Request $request, $locale) {
        if (in_array(@$locale, ['en', 'pl', 'de', 'ru', 'it', 'pt', 'es', 'fr'])) {
            $request->getSession()->set('_locale', $locale);
            $request->setLocale($locale);
        }

        return $this->redirectToRoute("_account_create_here");
    }

    /**
     * @Route("/create", name="_account_create")
     */
    public function createAction(Request $request) {
        $sl = $this->get('server_list');
        return $this->redirect($sl->getCreateAccountUrl($request));
    }

    /**
     * @Route("/view", name="_account_view")
     */
    public function viewAction() {
        $user = $this->get('security.token_storage')->getToken()->getUser();

        return $this->render(
            'SuplaBundle:Account:view.html.twig',
            [
                'user' => $user,
                'version' => $this->getParameter('supla.version'),
            ]
        );
    }

    /**
     * @Route("/reset_passwd/{token}", name="_account_reset_passwd")
     */
    public function resetPasswordAction(Request $request, $token) {
        $user_manager = $this->get('user_manager');

        if (($user = $user_manager->userByPasswordToken($token)) !== null) {
            $form = $this->createForm(
                ResetPasswordType::class,
                new ResetPassword()
            );

            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $user->setToken(null);
                $user->setPasswordRequestedAt(null);
                $user_manager->setPassword($form->getData()->getNewPassword(), $user, true);
                $this->get('session')->getFlashBag()->add('success', ['title' => 'Success', 'message' => 'Password has been changed!']);

                return $this->redirectToRoute("_auth_login");
            }

            return $this->render(
                'SuplaBundle:Account:resetpassword.html.twig',
                ['form' => $form->createView(),
                ]
            );
        } else {
            $this->get('session')->getFlashBag()->add('error', ['title' => 'Error', 'message' => 'Token does not exist']);
        }

        return $this->redirectToRoute("_auth_login");
    }

    /**
     * @Route("/api", name="_account_api")
     */
    public function apiSettingsAction() {
        $user = $this->get('security.token_storage')->getToken()->getUser();

        return $this->render(
            'SuplaBundle:Account:api.html.twig',
            ['user' => $user,
            ]
        );
    }

    /**
     * @Route("/ajax/changepassword", name="_account_ajax_changepassword")
     */
    public function ajaxChangePassword(Request $request) {
        $data = json_decode($request->getContent());
        $translator = $this->get('translator');
        $validator = $this->get('validator');

        $cp = new ChangePassword();
        $cp->setOldPassword(@$data->old_password);
        $cp->setNewPassword(@$data->new_password);
        $cp->setConfirmPassword(@$data->confirm_password);

        $errors = $validator->validate($cp);

        if (count($errors) > 0) {
            $result = ['flash' => ['title' => $translator->trans('Error'),
                'message' => $translator->trans($errors[0]->getMessage()),
                'type' => 'error'],
            ];

            return AjaxController::jsonResponse(false, $result);
        };

        $user = $this->get('security.token_storage')->getToken()->getUser();

        $this->get('user_manager')->setPassword($data->new_password, $user, false);

        return AjaxController::itemEdit($validator, $translator, $this->get('doctrine'), $user, 'Password has been changed!', '');
    }

    /**
     * @Route("/ajax/forgot_passwd_here", name="_account_ajax_forgot_passwd_here")
     */
    public function forgotPasswordHereAction(Request $request) {
        $translator = $this->get('translator');
        $user_manager = $this->get('user_manager');

        $data = json_decode($request->getContent());

        if (in_array(@$data->locale, ['en', 'pl', 'de', 'ru', 'it', 'pt', 'es', 'fr'])) {
            $request->getSession()->set('_locale', $data->locale);
            $request->setLocale($data->locale);
        }

        if (preg_match('/@/', @$data->email)
            && null !== ($user = $user = $user_manager->userByEmail($data->email))
            && $user_manager->paswordRequest($user) === true
        ) {
            $mailer = $this->get('supla_mailer');
            $mailer->sendResetPasswordEmailMessage($user);
        }

        return AjaxController::jsonResponse(true, null);
    }

    /**
     * @Route("/ajax/forgot_passwd", name="_account_ajax_forgot_passwd")
     */
    public function forgotPasswordAction(Request $request) {
        $data = json_decode($request->getContent());
        $username = @$data->email;

        if (preg_match('/@/', $username)) {
            $sl = $this->get('server_list');
            $server = $sl->getAuthServerForUser($request, $username);

            if (strlen(@$server) > 0) {
                AjaxController::remoteRequest('https://' . $server . $this->generateUrl('_account_ajax_forgot_passwd_here'), [
                    'email' => $username,
                    'locale' => $request->getLocale(),
                ]);
            }
        }

        return AjaxController::jsonResponse(true, null);
    }

    /**
     * @Route("/ajax/user_exists", name="_account_ajax_user_exists")
     */
    public function userExists(Request $request) {
        $exists = null;

        $sl = $this->get('server_list');

        if ($sl->requestAllowed()) {
            $data = json_decode($request->getContent());
            $user_manager = $this->get('user_manager');
            $user = $user_manager->userByEmail(@$data->username);

            $exists = $user !== null ? true : false;
        };

        return AjaxController::jsonResponse($exists !== null, ['exists' => $exists]);
    }

    /**
     * @Route("/user-timezone")
     * @Method("PUT")
     */
    public function updateUserTimezoneAction(Request $request) {
        $data = $request->request->all();
        try {
            $timezone = new \DateTimeZone($data['timezone']);
            $userManager = $this->get('user_manager');
            $userManager->updateTimeZone($this->getUser(), $timezone);
            return new JsonResponse(true);
        } catch (\Exception $e) {
            throw new BadRequestHttpException();
        }
    }

    /**
     * @Route("/schedulable-channels")
     * @Method("GET")
     */
    public function getSchedulableChannels() {
        $ioDeviceManager = $this->get('iodevice_manager');
        $schedulableChannels = $this->get('schedule_manager')->getSchedulableChannels($this->getUser());
        $channelToFunctionsMap = [];
        foreach ($schedulableChannels as $channel) {
            $channelToFunctionsMap[$channel->getId()] = $ioDeviceManager->functionActionMap()[$channel->getFunction()];
        }
        return new JsonResponse([
            'userChannels' => array_map(function (IODeviceChannel $channel) use ($ioDeviceManager) {
                return [
                    'id' => $channel->getId(),
                    'function' => $channel->getFunction(),
                    'functionName' => $ioDeviceManager->channelFunctionToString($channel->getFunction()),
                    'type' => $channel->getType(),
                    'caption' => $channel->getCaption(),
                    'device' => [
                        'id' => $channel->getIoDevice()->getId(),
                        'name' => $channel->getIoDevice()->getName(),
                        'location' => [
                            'id' => $channel->getIoDevice()->getLocation()->getId(),
                            'caption' => $channel->getIoDevice()->getLocation()->getCaption(),
                        ],
                    ],
                ];
            }, $schedulableChannels),
            'actionCaptions' => ScheduleAction::captions(),
            'channelFunctionMap' => $channelToFunctionsMap,
        ]);
    }
}
