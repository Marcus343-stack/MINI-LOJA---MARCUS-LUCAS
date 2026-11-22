# Vitrine Ipê

Uma página simples com catálogo inicial de 10 produtos ilustrados e uma guia separada para cadastrar produtos e funcionários.

## Como usar

É necessário ter PHP 8.0 ou mais recente com a extensão PDO_SQLite habilitada.
Depois de instalar o PHP, reabra o terminal para atualizar o `PATH`.

1. Abra um terminal na pasta do projeto.
2. Inicie o servidor local com `php -S 127.0.0.1:8000`.
3. Acesse `http://127.0.0.1:8000/Index.html` no navegador.

Os produtos e funcionários cadastrados são salvos em SQLite. Por padrão, o banco fica fora da pasta pública do site, em `../Mini-Loja-data/loja.sqlite`. Para definir outro caminho, configure a variável de ambiente `MINI_LOJA_DB_PATH` antes de iniciar o servidor e mantenha o arquivo fora da pasta pública.

O cadastro de funcionário pede nome, CPF e e-mail; o CPF deve conter 11 números e não pode estar repetido. A remoção de produtos e funcionários fica na guia **Cadastros**. O catálogo usa ilustrações vetoriais locais, sem dependência de imagens externas.

Esta aplicação foi preparada para uso local. Antes de disponibilizá-la na internet, configure autenticação e proteção adequada para os dados pessoais dos funcionários.
