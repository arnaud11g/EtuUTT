<?php

namespace Etu\Core\UserBundle\Controller;

use Doctrine\ORM\EntityManager;
use Etu\Core\ApiBundle\Entity\OauthClient;
use Etu\Core\CoreBundle\Framework\Definition\Controller;
use Etu\Core\CoreBundle\Form\BirthdayPickerType;
use Etu\Core\UserBundle\Entity\Course;
use Etu\Core\UserBundle\Entity\Member;
use Etu\Core\UserBundle\Entity\User;
use Etu\Core\UserBundle\Model\BadgesManager;
use Etu\Core\UserBundle\Model\CountriesManager;
use Etu\Core\UserBundle\Schedule\Helper\ScheduleBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

class ProfileController extends Controller
{
    /**
     * @Route("/user/profile", name="user_profile")
     * @Template()
     */
    public function profileAction()
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $apps = $em->createQueryBuilder()
            ->select('a, c')
            ->from('EtuCoreApiBundle:OauthAuthorization', 'a')
            ->innerJoin('a.client', 'c')
            ->where('a.user = :user')
            ->setParameter('user', $this->getUser()->getId())
            ->groupBy('a.client')
            ->getQuery()
            ->getResult();

        return array(
            'hasApps' => !empty($apps),
            'apps' => $apps,
        );
    }

    /**
     * @Route("/user/apps/revoke/{clientId}", name="user_profile_revoke_app")
     */
    public function appRevokeAction(OauthClient $client)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Remove authorization
        $em->createQueryBuilder()
            ->delete()
            ->from('EtuCoreApiBundle:OauthAuthorization', 'a')
            ->where('a.client = :client')
            ->andWhere('a.user = :user')
            ->setParameter('client', $client->getId())
            ->setParameter('user', $this->getUser()->getId())
            ->getQuery()
            ->execute();

        // Remove access_tokens
        $em->createQueryBuilder()
            ->delete()
            ->from('EtuCoreApiBundle:OauthAccessToken', 't')
            ->where('t.client = :client')
            ->andWhere('t.user = :user')
            ->setParameter('client', $client->getId())
            ->setParameter('user', $this->getUser()->getId())
            ->getQuery()
            ->execute();

        // Remove refresh_tokens
        $em->createQueryBuilder()
            ->delete()
            ->from('EtuCoreApiBundle:OauthRefreshToken', 't')
            ->where('t.client = :client')
            ->andWhere('t.user = :user')
            ->setParameter('client', $client->getId())
            ->setParameter('user', $this->getUser()->getId())
            ->getQuery()
            ->execute();

        // Remove authrization_code
        $em->createQueryBuilder()
            ->delete()
            ->from('EtuCoreApiBundle:OauthAuthorizationCode', 't')
            ->where('t.client = :client')
            ->andWhere('t.user = :user')
            ->setParameter('client', $client->getId())
            ->setParameter('user', $this->getUser()->getId())
            ->getQuery()
            ->execute();

        return $this->redirect($this->generateUrl('user_profile'));
    }

    /**
     * @Route("/user/profile/edit", name="user_profile_edit")
     * @Template()
     */
    public function profileEditAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var $user User */
        $user = $this->getUser();

        $privacyChoice = array(
            'choices' => array(
                User::PRIVACY_PUBLIC => 'user.privacy.public',
                User::PRIVACY_PRIVATE => 'user.privacy.private',
            ),
            'attr' => array(
                'class' => 'profileEdit-privacy-select',
            ),
            'required' => false,
        );

        $form = $this->createFormBuilder($user)
            ->add('phoneNumber', null, array('required' => false))
            ->add('phoneNumberPrivacy', ChoiceType::class, $privacyChoice)
            ->add('sex', ChoiceType::class, array('choices' => array(
                User::SEX_MALE => 'base.user.sex.male',
                User::SEX_FEMALE => 'base.user.sex.female',
            ), 'required' => false))
            ->add('sexPrivacy', ChoiceType::class, $privacyChoice)
            ->add('nationality', null, array('required' => false))
            ->add('nationalityPrivacy', ChoiceType::class, $privacyChoice)
            ->add('address', null, array('required' => false))
            ->add('addressPrivacy', ChoiceType::class, $privacyChoice)
            ->add('postalCode', null, array('required' => false))
            ->add('postalCodePrivacy', ChoiceType::class, $privacyChoice)
            ->add('city', null, array('required' => false))
            ->add('cityPrivacy', ChoiceType::class, $privacyChoice)
            ->add('country', ChoiceType::class, array('choices' => CountriesManager::getCountriesList(), 'required' => false))
            ->add('countryPrivacy', ChoiceType::class, $privacyChoice)
            ->add('birthday', BirthdayPickerType::class, array('required' => false))
            ->add('birthdayPrivacy', ChoiceType::class, $privacyChoice)
            ->add('birthdayDisplayOnlyAge', null, array('required' => false))
            ->add('personnalMail', null, array('required' => false))
            ->add('personnalMailPrivacy', ChoiceType::class, $privacyChoice)
            ->add('website', null, array('required' => false))
            ->add('facebook', null, array('required' => false))
            ->add('twitter', null, array('required' => false))
            ->add('linkedin', null, array('required' => false))
            ->add('viadeo', null, array('required' => false))
            ->getForm();

        if ($request->getMethod() == 'POST' && $form->handleRequest($request)->isValid()) {
            $em = $this->getDoctrine()->getManager();

            if ($user->getProfileCompletion() == 100) {
                BadgesManager::userAddBadge($user, 'profile_completed');
            } else {
                BadgesManager::userRemoveBadge($user, 'profile_completed');
            }

            BadgesManager::userPersistBadges($user);
            $em->persist($user);
            $em->flush();

            $this->get('session')->getFlashBag()->set('message', array(
                'type' => 'success',
                'message' => 'user.profile.profileEdit.confirm',
            ));

            return $this->redirect($this->generateUrl('user_profile_edit'));
        }

        // Avatar lightbox
        $avatarForm = $this->createFormBuilder($user)
            ->add('file', FileType::class)
            ->getForm();

        return array(
            'form' => $form->createView(),
            'avatarForm' => $avatarForm->createView(),
        );
    }

    /**
     * @Route("/user/profile/avatar", name="user_profile_avatar")
     * @Template()
     */
    public function profileAvatarAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var $user User */
        $user = $this->getUser();

        $form = $this->createFormBuilder($user)
            ->add('file', FileType::class)
            ->getForm();

        if ($request->getMethod() == 'POST' && $form->handleRequest($request)->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $file = $user->upload();

            $em->persist($user);
            $em->flush();

            return array(
                'result' => 'success',
                'data' => json_encode(array(
                    'filename' => '/uploads/photos/'.$file,
                )),
            );
        }

        /** @var $error FormError[] */
        $error = $form->get('file')->getErrors();

        return array(
            'result' => 'error',
            'data' => json_encode(array(
                'message' => (isset($error[0])) ? $error[0]->getMessage() : 'An error occured',
            )),
        );
    }

    /**
     * @Route("/user/trombi/edit", name="user_trombi_edit")
     * @Template()
     */
    public function trombiEditAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var $user User */
        $user = $this->getUser();

        $form = $this->createFormBuilder($user)
            ->add('surnom', null, array('required' => false))
            ->add('jadis', null, array('required' => false, 'attr' => array('class' => 'trombi-textarea')))
            ->add('passions', null, array('required' => false, 'attr' => array('class' => 'trombi-textarea')))
            ->getForm();

        if ($request->getMethod() == 'POST' && $form->handleRequest($request)->isValid()) {
            $em = $this->getDoctrine()->getManager();

            if ($user->getTrombiCompletion() == 100) {
                BadgesManager::userAddBadge($user, 'trombi_completed');
            } else {
                BadgesManager::userRemoveBadge($user, 'trombi_completed');
            }

            BadgesManager::userPersistBadges($user);
            $em->persist($user);
            $em->flush();

            $this->get('session')->getFlashBag()->set('message', array(
                'type' => 'success',
                'message' => 'user.profile.trombiEdit.confirm',
            ));

            return $this->redirect($this->generateUrl('user_profile'));
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/user/{login}", name="user_view")
     * @Template()
     */
    public function viewAction($login, Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_CORE_PROFIL');

        if ($login != $this->getUser()->getLogin()) {
            /** @var $em EntityManager */
            $em = $this->getDoctrine()->getManager();

            /** @var $user User */
            $user = $em->getRepository('EtuUserBundle:User')->findOneBy(array('login' => $login));

            if (!$user) {
                throw $this->createNotFoundException('Login "'.$login.'" not found');
            }
        } else {
            $user = $this->getUser();
        }

        $from = null;

        if (in_array($request->get('from'), array('search', 'profile', 'trombi', 'admin'))) {
            $from = $request->get('from');
        }

        return array(
            'user' => $user,
            'from' => $from,
        );
    }

    /**
     * @Route("/user/{login}/organizations", name="user_organizations")
     * @Template()
     */
    public function organizationsAction($login, Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_CORE_ORGAS');

        /** @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        /** @var $user User */
        $user = $em->getRepository('EtuUserBundle:User')->findOneBy(array('login' => $login));

        if (!$user) {
            throw $this->createNotFoundException('Login "'.$login.'" not found');
        }

        /** @var $memberships Member[] */
        $memberships = $em->createQueryBuilder()
            ->select('m, u, o')
            ->from('EtuUserBundle:Member', 'm')
            ->leftJoin('m.user', 'u')
            ->leftJoin('m.organization', 'o')
            ->where('u.login = :login')
            ->setParameter('login', $user->getLogin())
            ->getQuery()
            ->getResult();

        $from = null;

        if (in_array($request->get('from'), array('search', 'profile', 'trombi', 'admin'))) {
            $from = $request->get('from');
        }

        return array(
            'user' => $user,
            'memberships' => $memberships,
            'from' => $from,
        );
    }

    /**
     * @Route("/user/{login}/schedule/{day}", defaults={"day" = "current"}, name="user_view_schedule")
     * @Template()
     */
    public function scheduleAction($login, $day, Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_CORE_SCHEDULE');

        /** @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        if ($login != $this->getUser()->getLogin()) {

            /** @var $user User */
            $user = $em->getRepository('EtuUserBundle:User')->findOneBy(array('login' => $login));

            if (!$user) {
                throw $this->createNotFoundException('Login "'.$login.'" not found');
            }
        } else {
            $user = $this->getUser();
        }

        $from = null;

        if (in_array($request->get('from'), array('search', 'profile', 'trombi', 'admin'))) {
            $from = $request->get('from');
        }

        /** @var $courses Course[] */
        $courses = $em->getRepository('EtuUserBundle:Course')->findByUser($user);

        // Builder to create the schedule
        $builder = new ScheduleBuilder();

        foreach ($courses as $course) {
            $builder->addCourse($course);
        }

        $days = array(
            Course::DAY_MONDAY, Course::DAY_TUESDAY, Course::DAY_WENESDAY,
            Course::DAY_THURSDAY, Course::DAY_FRIDAY, Course::DAY_SATHURDAY,
        );

        if (!in_array($day, $days)) {
            if (date('w') == 0) { // Sunday
                $day = Course::DAY_MONDAY;
            } else {
                $day = $days[date('w') - 1];
            }
        }

        return array(
            'courses' => $builder->build(),
            'currentDay' => $day,
            'user' => $user,
            'from' => $from,
        );
    }

    /**
     * @Route("/user/{login}/badges", name="user_view_badges")
     * @Template()
     */
    public function badgesAction($login)
    {
        $this->denyAccessUnlessGranted('ROLE_CORE_PROFIL');

        if ($login != $this->getUser()->getLogin()) {
            /** @var $em EntityManager */
            $em = $this->getDoctrine()->getManager();

            /** @var $user User */
            $user = $em->getRepository('EtuUserBundle:User')->findOneBy(array('login' => $login));

            if (!$user) {
                throw $this->createNotFoundException('Login "'.$login.'" not found');
            }
        } else {
            $user = $this->getUser();
        }

        $from = null;

        if (in_array($request->get('from'), array('search', 'profile', 'trombi', 'admin'))) {
            $from = $request->get('from');
        }

        return array(
            'user' => $user,
            'from' => $from,
        );
    }
}
