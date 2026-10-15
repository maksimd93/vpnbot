#!/bin/bash
pwd=`pwd`
process_name="$pwd/update/update.sh"
current_pid=$$
pids=$(pgrep -f $process_name)
for pid in $pids; do
    if [ $pid -ne $current_pid ]; then
        kill -9 $pid
    fi
done

> $pwd/update/pipe
echo "$$" > $pwd/update/update_pid

while true
do
    cmd=$(cat $pwd/update/pipe)
    branch=$(cat $pwd/update/branch 2>/dev/null)
    if [[ -n "$cmd" ]]
    then
        key=$(cat $pwd/update/key)
        curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "stopping the bot"/')"
        docker compose down --remove-orphans
        if [[ "$cmd" == "1" ]]
        then
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "clearing the directory"/')"
            git reset --hard && git clean -fd
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "downloading the update"/')"
            git fetch
            if [[ -n "$branch" ]]
            then
                curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "changing branch"/')"
                git checkout -t origin/$branch || git checkout $branch
            fi
            curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "applying updates"/')"
            git pull --no-rebase --no-edit > ./update/message 2>&1 || { git merge --abort 2> /dev/null; echo "eternal: pull from origin failed, kept the current version" >> ./update/message; }
            # eternal: origin is the fork with local patches; also merge mercurykd's updates on top of them
            if git remote get-url upstream > /dev/null 2>&1
            then
                curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "merging upstream"/')"
                current_branch=$(git rev-parse --abbrev-ref HEAD)
                git fetch upstream --tags
                if git rev-parse --verify -q "upstream/$current_branch" > /dev/null
                then
                    if ! git merge --no-edit "upstream/$current_branch" >> ./update/message 2>&1
                    then
                        git merge --abort 2> /dev/null
                        echo "eternal: update from mercurykd/$current_branch conflicts with local patches and was skipped" >> ./update/message
                    fi
                fi
            fi
        fi
        curl -H "Content-Type: application/json" -X POST https://api.telegram.org/bot$key/editMessageText -d "$(cat $pwd/update/curl | sed 's/"text":"~t~"/"text": "launching the bot"/')"
        > $pwd/update/key
        > $pwd/update/curl
        IP=$(hostname -I | awk '{print $1}') VER=$(git describe --tags) docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate
        bash $pwd/update/update.sh &
        exit 0
    fi
    sleep 1
done