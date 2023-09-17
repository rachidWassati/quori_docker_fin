<?php

namespace App\Controller;

use DateTime;
use App\Entity\User;
use App\Form\UserType;
use App\Entity\ResetPassword;
use App\Services\FileUploader;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ResetPasswordRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class SecurityController extends AbstractController
{

    public function __construct(
        private $formLoginAuthenticator
    )
    {
        
    }

    #[Route('/signup', name: 'signup')]
    public function signup(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasherPassord, UserAuthenticatorInterface $userAuthenticator, MailerInterface $mailer, FileUploader $uploadedFile): Response
    {
        $newUser = new User();
        $userForm = $this->createForm(UserType::class, $newUser);
        $userForm->handleRequest($request);

        if($userForm->isSubmitted() && $userForm->isValid()) {
            $newUser->setPassword($hasherPassord->hashPassword($newUser, $newUser->getPassword()));

            $avatar = $userForm->get('pictureFile')->getData();
            if($avatar) {
                $newUser->setAvatar($uploadedFile->uploadProfileImage($avatar));
            } else {
                $newUser->setAvatar('profiles/default_profile.png');
            }

            $em->persist($newUser);
            $em->flush();

            $this->addFlash('success', 'Bienvenue sur le site Quori !');

            $email = new TemplatedEmail();
            $email->to($newUser->getEmail())
                    ->subject('Bienvenue sur Quori')
                    ->htmlTemplate('@email_templates/welcome.html.twig')
                    ->context(['username' => $newUser->getFirstname()]);
            $mailer->send($email);

            // Authentifie automatiquement l'utilisateur
            return $userAuthenticator->authenticateUser($newUser, $this->formLoginAuthenticator, $request);
        }

        return $this->render('security/signup.html.twig', ['form' => $userForm]);
    }


    #[Route('/signin', name: 'signin')]
    public function signin(AuthenticationUtils $authenticationUtils): Response
    {
        if($this->getUser()) {
            return $this->redirectToRoute('home');
        }
        
        $error = $authenticationUtils->getLastAuthenticationError();
        $username = $authenticationUtils->getLastUsername();

        return $this->render('security/signin.html.twig', [
            'error' => $error,
            'username' => $username
        ]);
    }


    #[Route('/signout', name: 'signout')]
    public function signout()
    {
    }

    #[Route('/reset-password-request', name:'reset-password-request')]
    public function resetPasswordRequest(Request $request, UserRepository $userRepository, ResetPasswordRepository $resetPasswordRepository, EntityManagerInterface $em, MailerInterface $mailer, RateLimiterFactory $passwordRecoveryLimiter) {

        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        
        $emailResetForm = $this->createFormBuilder()
        ->add('email', EmailType::class, [
            'constraints' => [
                new NotBlank(['message' => 'Veuillez renseigner ce champ']),
                new Email(['message' => 'Veuillez entrer un email valide'])
                ]
                ])
                ->getForm();
                
                $emailResetForm->handleRequest($request);
                
        if($emailResetForm->isSubmitted() && $emailResetForm->isValid()) {
            if(!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Vous devez attendre 1 heure pour refaire une demande');
                return $this->redirectToRoute('signin');
            }

            $email = $emailResetForm->get('email')->getData();
            $user = $userRepository->findOneBy(['email' => $email]);
            if($user) {
                $oldResetPasswordRequest = $resetPasswordRepository->findOneBy(['user' => $user]);
                if($oldResetPasswordRequest) {
                    $em->remove($oldResetPasswordRequest);
                    $em->flush();
                }

                $token = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(40))), 0, 20);
                $newResetPasswordRequest = new ResetPassword();
                $newResetPasswordRequest->setUser($user)
                                        ->setExpiredAt(new \DateTimeImmutable('+2 hours'))
                                        ->setToken(sha1($token));

                $em->persist($newResetPasswordRequest);
                $em->flush();

                // on envoie le mail
                $resetEmail = new TemplatedEmail();
                $resetEmail->to($email)
                            ->subject('Demande de reinitialisation de mot de passe')
                            ->htmlTemplate('@email_templates/reset-password-request.html.twig')
                            ->context([
                                'username' => $user->getFirstname(),
                                'token' => $token
                            ]);
                $mailer->send($resetEmail);

                $this->addFlash('success', 'Un email vous a ete envoye');

                return $this->redirectToRoute('home');
            }
        }

        return $this->render('security/reset-password-request.html.twig', ['form' => $emailResetForm]);
    }

    #[Route('/reset-password/{token}', name: 'reset-password')]
    public function resetPassword(Request $request, string $token, ResetPasswordRepository $resetPasswordRepository, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, RateLimiterFactory $passwordRecoveryLimiter) {
        
        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        if(!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Vous devez attendre 1 heure pour refaire une demande');
            return $this->redirectToRoute('signin');
        }

        $resetPasswordEntry = $resetPasswordRepository->findOneBy(['token' => sha1($token)]);

        if(!$resetPasswordEntry || $resetPasswordEntry->getExpiredAt() < new DateTime('now')) {
            if($resetPasswordEntry) {
                $em->remove($resetPasswordEntry);
                $em->flush();
            }
            $this->addFlash('error', 'Votre demande a expire, veuillez la renouveller');
            return $this->redirectToRoute('signin');
        }

        $passwordResetForm = $this->createFormBuilder()
                                    ->add('password', PasswordType::class, [
                                        'constraints' => [
                                            new Length(['min' => 6, 'minMessage' => 'Le mot de passe est trop court']),
                                            new NotBlank(['message' => 'Veuillez renseigner ce champ'])
                                        ]
                                    ])
                                    ->getForm();
        $passwordResetForm->handleRequest($request);

        if($passwordResetForm->isSubmitted() && $passwordResetForm->isValid()) {
            $newPassword = $passwordResetForm->get('password')->getData();
            $user = $resetPasswordEntry->getUser();
            $hashedPassword = $hasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);

            $em->remove($resetPasswordEntry);
            $em->flush();
            $this->addFlash('success', 'Votre mot de passe a et modifie');
            return $this->redirectToRoute('signin');
        }

        return $this->render('security/reset-password-form.html.twig', ['form' => $passwordResetForm]);
    }
}
