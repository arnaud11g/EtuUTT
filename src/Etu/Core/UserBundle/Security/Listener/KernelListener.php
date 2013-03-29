<?php

namespace Etu\Core\UserBundle\Security\Listener;

use Doctrine\Bundle\DoctrineBundle\Registry;

use Etu\Core\UserBundle\Entity\User;
use Etu\Core\UserBundle\Security\Authentication\AnonymousToken;
use Etu\Core\UserBundle\Security\Authentication\UserToken;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\SecurityContext;

/**
 * Listener to connect CAS and Symfony.
 *
 * @author Titouan
 */
class KernelListener
{
	/**
	 * @var SecurityContext
	 */
	protected $securityContext;

	/**
	 * @var Session
	 */
	protected $session;

	/**
	 * @var Registry
	 */
	protected $doctrine;

	/**
	 * Constructor
	 *
	 * @param SecurityContext $securityContext
	 * @param Session         $session
	 * @param Registry        $doctrine
	 */
	public function __construct(SecurityContext $securityContext, Session $session, Registry $doctrine)
	{
		$this->securityContext = $securityContext;
		$this->session = $session;
		$this->doctrine = $doctrine;
	}

	/**
	 * Called on kernel.request event. Find current user.
	 */
	public function onKernelRequest()
	{
		if (is_int($this->session->get('user')) && $this->session->get('user') > 0) {
			$user = $this->doctrine->getManager()
				->getRepository('EtuUserBundle:User')
				->findOneBy(array('id' => $this->session->get('user')));

			$this->securityContext->setToken(new UserToken($user));
		} else {
			$this->securityContext->setToken(new AnonymousToken());
		}
	}
}