set :application, "paypal-integration"
set :user, "deploy"
set :domain, "#{user}@vps1.interconlarp.org"
set :repository, "git@vps1.interconlarp.org:paypal-integration.git"
set :revision, "origin/master"
set :deploy_to, "/var/www/#{application}"

namespace :vlad do
  Rake.clear_tasks('vlad:update_symlinks')

  task :update_symlinks do
  end
end
