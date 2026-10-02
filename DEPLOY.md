# Publicar em hospedagem compartilhada (PHP + MySQL)

Versão 3.5.0. O app funciona em dois modos, escolhidos automaticamente:

* **Online** (login, grupos, dados no seu servidor): quando roda na sua hospedagem com a pasta `api/`.
* **Local/piloto** (sem login, dados só no navegador): no GitHub Pages ou abrindo o arquivo direto.

Os dados ficam no seu banco MySQL da o painel da sua hospedagem. Senhas são guardadas criptografadas (`password_hash`).

## Passo a passo

1. **Subdomínio**: no painel da o painel da sua hospedagem, crie um subdomínio, por exemplo `contract.example.org`, e ative o SSL (Let's Encrypt).
2. **PHP**: nas configurações de PHP desse subdomínio, escolha **PHP 8.2 ou mais novo**.
3. **Banco de dados**: em *MySQL-Datenbanken*, crie um banco e anote host, nome do banco, usuário e senha.
4. **Enviar arquivos**: descompacte `deploy.zip` e envie **todo o conteúdo** para a pasta do subdomínio
   (gerenciador de arquivos da o painel da sua hospedagem ou FTP). Inclua o arquivo oculto `.htaccess`.
5. **Instalar**: abra `https://contract.example.org/install.php`, preencha os dados do banco e crie a sua conta de docente.
6. **Apague `install.php`** do servidor.
7. **Criar grupos**: abra `https://contract.example.org/teacher.html`, entre com a conta de docente, crie os grupos e baixe os códigos (CSV).
8. **Alunos**: abrem `https://contract.example.org`, clicam em *Create account*, digitam o código do grupo e preenchem o contrato.

## Painel do aluno (`student.html`)

Visão geral do que já foi feito, o Individual Collaboration Contract e os links para as ferramentas externas
(DISC, Initial Team Reflection, Weekly Reflection). O contrato individual é visível apenas para o próprio aluno,
para os coaches e para os pesquisadores, nunca para os colegas de equipe.

## Administração (`admin.html`)

Só para a conta dona. Três abas:

* **People**: criar contas de coach e de pesquisador, mudar papéis e gerar senha temporária para qualquer pessoa.
* **Groups & data**: lista de grupos com membros, contrato e contratos individuais; atribuição de coaches por grupo;
  e a limpeza de dados de um grupo.
* **System**: contagens do banco, versão do esquema e exportações.

**Atribuição de coaches**: um coach sem grupo atribuído vê todos os grupos. A partir do primeiro grupo atribuído,
passa a ver apenas os seus, no painel, nos contratos individuais e na atividade ao vivo. Pesquisadores e a conta dona
veem tudo. A restrição é aplicada no servidor, não apenas na tela.

## Painel de coaching (`coach.html`)

Abas: **Overview**, **Collaboration Contract**, **Reflection Tool**, **DISC Reflection**,
**Session Notes** e **People**. O antigo `teacher.html` redireciona para cá.

* **Overview**: um cartão por grupo, com membros, progresso, última atividade e a última nota de sessão.
* **Collaboration Contract**: criar grupos, painel ao vivo, reset de senha de aluno e exportações.
* **Individual Contracts**: os contratos individuais de cada aluno, com exportação.
* **Team Contract**: além do painel ao vivo, cada grupo tem rodadas, e o detalhe mostra o que mudou de uma rodada para a outra.
* **Reflection Tools / DISC**: links para as ferramentas externas, que são anônimas e não alimentam o painel.
A gestão de contas e a limpeza de dados saíram daqui e ficam em `admin.html`.
* **Session Notes**: data, grupo, presentes, notas e próximos passos. Cada coach vê apenas as próprias notas,
  e elas ficam fora de toda exportação de pesquisa.
* **People** (só para a conta dona): criar contas de coach e de pesquisador, com senha temporária mostrada uma vez.
  Coach vê grupos, contratos e as próprias notas. Pesquisador vê grupos, contratos e exportações, sem as notas.

## Backup

Em *MySQL-Datenbanken → phpMyAdmin → Exportieren*, baixe o banco de tempos em tempos (por exemplo, ao fim de cada aula).

## Arquivos

* `api/index.php`: API (login, grupos, salvamento com controle de versão, eventos).
* `api/lib.php`: banco e sessões. `api/config.php` é criado pelo instalador e fica protegido por `.htaccess`.
* `cloud.js`: ligação entre o app e a API. `config.js`: modo (`auto` por padrão).
