# nor
New Order Submission

- [Git](#git)
    - [Rules for working with Git](#some-git-rules)
    - [Writing a good commit message](#writing-good-commit-messages)
- [Documentation](#documentation)   

<a name="git"></a>

## 1. Git

<a name="some-git-rules"></a>

### 1.1 Rules for working with Git
A set of rules to keep in mind:

* Develop in `stage/*` or `prod/*` branch.
    _Why:_
    > This way, all the work is done in isolation on a separate branch rather than on the main branch. This will allow you to create multiple pull requests without confusion. You can continue development without polluting the `stg` or `production` branch with potentially unstable and unfinished code. [To learn more...](https://www.atlassian.com/git/tutorials/comparing-workflows#feature-branch-workflow)

* The branch should look like this `stage/bug/TASK` (example: `stage/bugfix/nor-111`) if it's bug; and `stage/feature/TASK` (example: `stage/feature/nor-111`) if it's feature. 
    

* Never push commits directly to the `stg` or `production` branches. Create a Pull Request.
    
    _Why:_
    > This way, team members will receive a notification that work on a new feature has been completed. It will also facilitate the code review process and provide a forum for discussing the proposed feature.

<a name="writing-good-commit-messages"></a>

### 1.2 Writing a good commit message

* The commit must ALWAYS include a link to the task.

* A good commit guide and following it will make working with Git and collaborating with other developers easier. Here are some rules ([source](https://chris.beams.io/posts/git-commit/#seven-rules))

<a name="documentation"></a>

## 2. Documentation

STAGE

* branch - `stg`
* view - 5334 in 989 table ([link](https://hpe-rfb.it.hpe.com/form/989/nor-qids-stg))

PRODUCTION

* branch - `production`
* view - 5375 in 989 table ([link](https://hpe-rfb.it.hpe.com/form/989/nor-prod-qids))