<?php

namespace App\Controller\Admin;

use App\Entity\Address;
use App\Entity\User;
use App\Form\AddressType;
use App\Form\AdminUserType;
use App\Repository\AddressRepository;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditLogRepository $audit,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/admin/users', name: 'app_admin_user', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $q = $request->query->getString('q');

        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findPage($q, $page),
            'q' => $q,
            'page' => $page,
        ]);
    }

    #[Route('/admin/users/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = (new User())->setIsVerified(true);
        $form = $this->createForm(AdminUserType::class, $user, ['require_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyRoleAndPassword($form, $user);
            $this->em->persist($user);
            $this->em->flush();
            $this->audit->record($this->admin(), 'user.create', 'User', (int) $user->getId(), ['email' => $user->getEmail(), 'admin' => $this->isAdmin($user)]);
            $this->addFlash('success', sprintf('%s was created.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user, 'title' => 'New user']);
    }

    #[Route('/admin/users/{id}', name: 'app_admin_user_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(User $user, UserRepository $users, OrderRepository $orders, AddressRepository $addresses): Response
    {
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'isAdmin' => $this->isAdmin($user),
            'addresses' => $addresses->findBy(['owner' => $user], ['isDefault' => 'DESC', 'id' => 'ASC']),
            'orders' => $orders->findForCustomer($user, null, 1, 10),
            'blockers' => $users->deletionBlockers($user),
        ]);
    }

    #[Route('/admin/users/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        $self = $user === $this->admin();
        $form = $this->createForm(AdminUserType::class, $user, ['is_admin' => $this->isAdmin($user), 'lock_role' => $self]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyRoleAndPassword($form, $user, $self);
            $this->em->flush();
            $this->audit->record($this->admin(), 'user.update', 'User', (int) $user->getId(), [
                'email' => $user->getEmail(),
                'admin' => $this->isAdmin($user),
                'active' => $user->isActive(),
                'passwordChanged' => '' !== (string) $form->get('plainPassword')->getData(),
            ]);
            $this->addFlash('success', 'User saved.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user, 'title' => 'Edit '.$user->getEmail()]);
    }

    #[Route('/admin/users/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        User $user,
        Request $request,
        UserRepository $users,
        #[Autowire('%kernel.project_dir%/public/uploads/reviews')] string $reviewDir,
    ): Response {
        $this->checkToken($request, 'user-delete'.$user->getId());

        if ($user === $this->admin()) {
            $this->addFlash('error', 'You cannot delete your own account.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        $blockers = $users->deletionBlockers($user);
        if ([] !== $blockers) {
            $list = implode(', ', array_map(static fn (string $label, int $n): string => $n.' '.$label, array_keys($blockers), $blockers));
            $this->addFlash('error', sprintf('This account has %s, which must be kept. Deactivate it instead.', $list));

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        $id = (int) $user->getId();
        $email = (string) $user->getEmail();
        foreach ($users->deleteWithOwnedData($user) as $photo) {
            if (is_file($reviewDir.'/'.basename($photo))) {
                unlink($reviewDir.'/'.basename($photo));
            }
        }
        $this->audit->record($this->admin(), 'user.delete', 'User', $id, ['email' => $email]);
        $this->addFlash('success', sprintf('%s was deleted.', $email));

        return $this->redirectToRoute('app_admin_user', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/users/{id}/toggle', name: 'app_admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(User $user, Request $request): Response
    {
        $this->checkToken($request, 'user'.$user->getId());

        if ($user === $this->admin()) {
            $this->addFlash('error', 'You cannot deactivate your own account.');
        } else {
            $user->setIsActive(!$user->isActive());
            $this->em->flush();
            $this->audit->record($this->admin(), $user->isActive() ? 'user.activate' : 'user.deactivate', 'User', (int) $user->getId());
            $this->addFlash('success', sprintf('%s is now %s.', $user->getEmail(), $user->isActive() ? 'active' : 'deactivated'));
        }

        $back = $request->request->getString('_back');

        return 'show' === $back
            ? $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_admin_user', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/users/{id}/addresses/new', name: 'app_admin_user_address_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function newAddress(User $user, Request $request, AddressRepository $addresses): Response
    {
        $address = (new Address())->setOwner($user);
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (0 === $addresses->countForUser($user)) {
                $address->setIsDefault(true);
            }
            if ($address->isDefault()) {
                $addresses->clearDefaultExcept($user, $address);
            }
            $this->em->persist($address);
            $this->em->flush();
            $this->addFlash('success', 'Address added.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/address_form.html.twig', ['form' => $form, 'user' => $user, 'title' => 'New address for '.$user->getEmail()]);
    }

    #[Route('/admin/addresses/{id}/edit', name: 'app_admin_address_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editAddress(Address $address, Request $request, AddressRepository $addresses): Response
    {
        $user = $address->getOwner();
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($address->isDefault()) {
                $addresses->clearDefaultExcept($user, $address);
            }
            $this->em->flush();
            $this->addFlash('success', 'Address saved.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user/address_form.html.twig', ['form' => $form, 'user' => $user, 'title' => 'Edit address']);
    }

    #[Route('/admin/addresses/{id}/delete', name: 'app_admin_address_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAddress(Address $address, Request $request): Response
    {
        $this->checkToken($request, 'address-delete'.$address->getId());
        $user = $address->getOwner();

        try {
            $this->em->remove($address);
            $this->em->flush();
            $this->addFlash('success', 'Address deleted.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'This address is on an order, so it has to stay.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()], Response::HTTP_SEE_OTHER);
    }

    private function applyRoleAndPassword(FormInterface $form, User $user, bool $self = false): void
    {
        if (!$self) {
            $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']));
            if (true === $form->get('admin')->getData()) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);
        }

        $password = (string) $form->get('plainPassword')->getData();
        if ('' !== $password) {
            $user->setPassword($this->hasher->hashPassword($user, $password));
        }
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
