# Ipê & Afeto | Loja do Marcus Lucas

Uma loja virtual de acessórios, presentes e pequenos achados para o dia a dia, com catálogo público e área de gerenciamento para funcionários.

## Como usar

É necessário ter PHP 8.0 ou mais recente com a extensão PDO_SQLite habilitada.
Depois de instalar o PHP, reabra o terminal para atualizar o `PATH`.

1. Abra um terminal na pasta do projeto.
2. Inicie o servidor local com `php -S 127.0.0.1:8000`.
3. Acesse `http://127.0.0.1:8000/Index.html` no navegador.

Os produtos e funcionários cadastrados são salvos em SQLite. Por padrão, o banco fica fora da pasta pública do site, em `../Mini-Loja-data/loja.sqlite`. Para definir outro caminho, configure a variável de ambiente `MINI_LOJA_DB_PATH` antes de iniciar o servidor e mantenha o arquivo fora da pasta pública.

Na guia **Cadastros**, um funcionário pode criar uma conta com nome, CPF, e-mail e senha (mínimo de 8 caracteres), ou entrar com e-mail e senha. As senhas são armazenadas com hash. Cadastros antigos de funcionários podem definir uma senha ao informar o mesmo CPF e e-mail já registrados. Depois de entrar, a equipe pode cadastrar produtos com imagem JPG, PNG, WEBP ou GIF de até 5 MB, além de remover produtos e funcionários. As operações de gerenciamento exigem login.

As imagens enviadas ficam na pasta `uploads/`, e o banco é atualizado automaticamente para incluir os campos novos sem apagar os cadastros existentes. O catálogo público inclui ilustrações vetoriais originais para os dez produtos iniciais, sem imagens de terceiros; fotos enviadas por funcionários aparecem no lugar delas.

Esta aplicação foi preparada para uso local. Antes de disponibilizá-la na internet, use HTTPS e configure proteções adequadas para os dados pessoais dos funcionários.
