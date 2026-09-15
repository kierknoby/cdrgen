<?php

namespace CdrGen\Database;

final class LiveRunGuard
{
    private $repository;
    private $store;
    private $accountcode;
    private $retain = false;
    private $cleanupArmed = false;
    private $cleaned = false;

    public function __construct(CdrRepository $repository, ActiveRunStore $store)
    {
        $this->repository = $repository;
        $this->store = $store;
    }

    public function recoverAndRequireClean(): array
    {
        $record = $this->store->read();
        $recovered = [];
        if ($record !== null) {
            $accountcode = (string) $record['accountcode'];
            $this->repository->cleanup($accountcode);
            if ($this->repository->countExact($accountcode) !== 0) {
                throw new \RuntimeException("Recovery cleanup did not remove {$accountcode}");
            }
            $this->store->clear();
            $recovered[$accountcode] = 'recovery-record';
        }
        foreach ($this->repository->recoverRecognizedGeneratedRuns() as $accountcode => $count) {
            $recovered[$accountcode] = "database-marker:{$count}";
        }
        $this->repository->assertNoGeneratedRows();
        return $recovered;
    }

    public function prepare(string $accountcode, bool $retain): void
    {
        AccountcodePolicy::assertValid($accountcode);
        $this->repository->assertNoGeneratedRows();
        $this->store->write($accountcode, $retain);
        $this->accountcode = $accountcode;
        $this->retain = $retain;
    }

    /** Arm cleanup before the transaction begins, closing the post-COMMIT signal race. */
    public function armCleanup(): void
    {
        if ($this->accountcode === null) {
            throw new \LogicException('Cannot arm an unprepared CDRgen run');
        }
        $this->cleanupArmed = true;
    }

    public function cleanupTemporary(): int
    {
        if ($this->cleaned || $this->accountcode === null) {
            return 0;
        }
        if ($this->retain) {
            return 0;
        }
        $deleted = $this->repository->cleanup($this->accountcode);
        $this->repository->assertNoGeneratedRows();
        $this->store->clear();
        $this->cleaned = true;
        return $deleted;
    }

    public function emergencyCleanup(): void
    {
        if (!$this->cleanupArmed || $this->retain || $this->cleaned) {
            return;
        }
        $this->cleanupTemporary();
    }

    public function isRetained(): bool
    {
        return $this->retain;
    }

    public function recoveryPath(): string
    {
        return $this->store->path();
    }
}
