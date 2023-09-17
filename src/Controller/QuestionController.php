<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Question;
use App\Entity\Vote;
use App\Form\CommentType;
use App\Form\QuestionType;
use App\Repository\QuestionRepository;
use App\Repository\VoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/question', name: 'question_')]
class QuestionController extends AbstractController
{
    #[Route('/ask', name: 'ask')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function ask(Request $request, EntityManagerInterface $em): Response
    {
        $newQuestion = new Question();
        $formQuestion = $this->createForm(QuestionType::class, $newQuestion);
        $formQuestion->handleRequest($request);

        if($formQuestion->isSubmitted() && $formQuestion->isValid()) {
            $newQuestion->setRating(0)
                        ->setNbResponse(0)
                        ->setCreatedAt(new \DateTimeImmutable())
                        ->setAuthor($this->getUser());
            
            $em->persist($newQuestion);
            $em->flush();

            $this->addFlash('success', 'Votre question a bien ete publiee');
            return $this->redirectToRoute('home');
        }


        return $this->render('question/ask.html.twig', [
            'form' => $formQuestion
        ]);
    }

    #[Route('/show/{id}', name: 'show')]
    public function show(int $id, Request $request, EntityManagerInterface $em, QuestionRepository $questionRepository) : Response
    {
        $question = $questionRepository->findQuestionWithCommentsAndAuthors($id);
        
        $options = [
            'question' => $question
        ];

        $user = $this->getUser();

        if($user) {
            $newComment = new Comment();
            $formComment = $this->createForm(CommentType::class, $newComment);
            $formComment->handleRequest($request);

            if($formComment->isSubmitted() && $formComment->isValid()) {
                $newComment->setRating(0)
                            ->setCreatedAt(new \DateTimeImmutable())
                            ->setQuestion($question)
                            ->setAuthor($user);
                $question->setNbResponse($question->getNbResponse() + 1);

                $em->persist($newComment);
                $em->flush();

                $this->addFlash('success', 'Votre reponse a ete publiee');

                return $this->redirect($request->getUri());
            }
            $options['form'] = $formComment;
        }

        return $this->render('question/show.html.twig', $options);
    }

    #[Route('/rate/{id}/{score}', name: 'rate')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function rate(Question $question, int $score, EntityManagerInterface $em, VoteRepository $voteRepository) : Response
    {
        $user = $this->getUser();

        if($user !== $question->getAuthor()) {
            $vote = $voteRepository->findOneBy(['author' => $user, 'question' => $question]);

            if($vote) {
                if(($vote->isIsLiked() && $score > 0) || (!$vote->isIsLiked() && $score < 0)) {
                    $em->remove($vote);
                    $question->setRating($question->getRating() + ($score > 0 ? -1 : 1));
                } else {
                    $vote->setIsLiked(!$vote->isIsLiked());
                    $question->setRating($question->getRating() + ($score > 0 ? 2 : -2));
                }
            } else {
                $newVote = new Vote();
                $newVote->setAuthor($user)
                        ->setQuestion($question)
                        ->setIsLiked($score > 0);
                $em->persist($newVote);
                $question->setRating($question->getRating() + $score);
            }
            $em->flush();
        }
        
        return $this->render('partials/_rating.html.twig', [
            'from' => 'question',
            'id' => $question->getId(),
            'rating' => $question->getRating()
        ]);
    }
}
