<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Vote;
use App\Repository\VoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/comment', name: 'comment_')]
class CommentController extends AbstractController
{
    #[Route('/rate/{id}/{score}', name: 'rate')]
    public function rate(Comment $comment, int $score, EntityManagerInterface $em, Request $request, VoteRepository $voteRepository) : Response
    {
        $user = $this->getUser();

        if($user !== $comment->getAuthor()) {
            $vote = $voteRepository->findOneBy(['author' => $user, 'comment' => $comment]);

            if($vote) {
                if(($vote->isIsLiked() && $score > 0) || (!$vote->isIsLiked() && $score < 0)) {
                    $em->remove($vote);
                    $comment->setRating($comment->getRating() + ($score > 0 ? -1 : 1));
                } else {
                    $vote->setIsLiked(!$vote->isIsLiked());
                    $comment->setRating($comment->getRating() + ($score > 0 ? 2 : -2));
                }
            } else {
                $newVote = new Vote();
                $newVote->setAuthor($user)
                        ->setComment($comment)
                        ->setIsLiked($score > 0);
                $em->persist($newVote);
                $comment->setRating($comment->getRating() + $score);
            }
            $em->flush();
        }
        
        return $this->render('partials/_rating.html.twig', [
            'from' => 'comment',
            'id' => $comment->getId(),
            'rating' => $comment->getRating()
        ]);
    }
}
