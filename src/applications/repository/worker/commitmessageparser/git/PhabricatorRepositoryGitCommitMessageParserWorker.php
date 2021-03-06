<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorRepositoryGitCommitMessageParserWorker
  extends PhabricatorRepositoryCommitMessageParserWorker {

  public function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    // NOTE: %B was introduced somewhat recently in git's history, so pull
    // commit message information with %s and %b instead.
    list($info) = $repository->execxLocalCommand(
      'log -n 1 --pretty=format:%%e%%x00%%an%%x00%%s%%n%%n%%b %s',
      $commit->getCommitIdentifier());

    list($encoding, $author, $message) = explode("\0", $info);

    if ($encoding != "UTF-8") {
      $author = mb_convert_encoding($author, 'UTF-8', $encoding);
      $message = mb_convert_encoding($message, 'UTF-8', $encoding);
    }

    // Make sure these are valid UTF-8.
    $author = phutil_utf8ize($author);
    $message = phutil_utf8ize($message);
    $message = trim($message);

    $this->updateCommitData($author, $message);

    if ($this->shouldQueueFollowupTasks()) {
      $task = new PhabricatorWorkerTask();
      $task->setTaskClass('PhabricatorRepositoryGitCommitChangeParserWorker');
      $task->setData(
        array(
          'commitID' => $commit->getID(),
        ));
      $task->save();
    }
  }

  protected function getCommitHashes(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    list($stdout) = $repository->execxLocalCommand(
      'log -n 1 --format=%s %s --',
      '%T',
      $commit->getCommitIdentifier());

    $commit_hash = $commit->getCommitIdentifier();
    $tree_hash = trim($stdout);

    return array(
      array(DifferentialRevisionHash::HASH_GIT_COMMIT, $commit_hash),
      array(DifferentialRevisionHash::HASH_GIT_TREE, $tree_hash),
    );
  }

}
