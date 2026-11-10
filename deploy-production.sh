#!/bin/sh
set -eu

# vk-to-gpt is a Node.js service. Configure systemd service "vk-to-gpt"
# once on the server, then deployments install production dependencies
# and restart that service.
npm install --omit=dev
sudo systemctl restart vk-to-gpt
