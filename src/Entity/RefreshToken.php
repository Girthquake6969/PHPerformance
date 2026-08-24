<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Model\AbstractRefreshToken;

// unfortunately without this explicit reference to their repository class, we get an error :(
#[ORM\Entity(repositoryClass: 'Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository')]
#[ORM\Table(name: 'refresh_tokens')]
// TODO: figure out if it makes more sense to individually implement getRowData here vs making a separate BaseRefreshToken
class RefreshToken extends AbstractRefreshToken
{
    #[ORM\Id]
    #[ORM\Column(name: 'refresh_token', type: 'string', unique: true)]
    protected $refreshToken = null;

    #[ORM\Column(name: 'user_id', type: 'string')]
    protected ?string $userId = null;

    #[ORM\Column(name: 'expires_at', type: 'integer')]
    protected ?int $expiresAt = null;

    public function setUsername($userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->userId;
    }

    // $expiresAt will come in as a DateTime, so we need to convert it to a unix timestamp
    public function setValid($expiresAt): self
    {
        $this->expiresAt = $expiresAt instanceof \DateTimeInterface ? $expiresAt->getTimestamp() : (int) $expiresAt;
        return $this;
    }

    public function getValid(): ?\DateTimeInterface
    {
        return (new \DateTimeImmutable())->setTimestamp($this->expiresAt);
    }

    public function isValid(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt >= (new \DateTimeImmutable())->getTimestamp();
    }
}
