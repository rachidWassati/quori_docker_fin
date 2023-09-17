<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Services\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/user/profile', name: 'user_')]
class UserController extends AbstractController
{
    
    #[Route('/', name: 'current_profile')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function diplayCurrentUserProfile(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, FileUploader $fileUploader): Response
    {
        /** @var User */
        $currentUser = $this->getUser();
        $userForm = $this->createForm(UserType::class, $currentUser);
        $userForm->remove('password');
        $userForm->add('newPassword', PasswordType::class, [
            'label' => 'Nouveau mot de passe',
            'required' => false
        ]);
        $userForm->handleRequest($request);

        if($userForm->isSubmitted() && $userForm->isValid()) {
            $newPassord = $currentUser->getNewPassword();
            if($newPassord) {
                $hash = $hasher->hashPassword($currentUser, $newPassord);
                $currentUser->setPassword($hash);
            }

            $picture = $userForm->get('pictureFile')->getData();
            if($picture) {
                $currentUser->setAvatar($fileUploader->uploadProfileImage($picture, $currentUser->getAvatar()));
            }

            $em->flush();
            $this->addFlash('success', 'Modifications sauvegardees');
        }

        return $this->render('user/current-user-profile.html.twig', ['form' => $userForm]);
    }

    #[Route('/questions', name: 'show_questions')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function showQuestions(): Response
    {
        return $this->render('user/show-questions.html.twig');
    }

    #[Route('/comments', name: 'show_comments')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function showComments(): Response
    {
        return $this->render('user/show-comments.html.twig');
    }

    #[Route("/{id}", name: 'profile')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function diplayUserProfile(User $user): Response
    {
        $currentUser = $this->getUser();
        if($user == $currentUser) {
            return $this->redirectToRoute('user_current_profile');
        }
        
        return $this->render('user/user-profile.html.twig', ['user' => $user]);
    }

}
