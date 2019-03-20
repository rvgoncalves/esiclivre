<?php
/**********************************************************************************
 Sistema e-SIC Livre: sistema de acesso a informação baseado na lei de acesso.

 Copyright (C) 2014 Prefeitura Municipal do Natal

 Este programa é software livre; você pode redistribuí-lo e/ou
 modificá-lo sob os termos da Licença GPL2.
***********************************************************************************/

use Esic\Container;
use Esic\Senders\Mail;

require_once ("database.php");
require_once ("funcoes.php");
require_once ("config.php");

function sendMail($to, $subject,$body,$from="",$fromname="")
{
	if (USE_PHPMAILER) {
		return PHPMailerSendMail($to, $subject,$body,null,$fromname);
	}
	else
		return LocalSendMail($to, $subject,$body,$from,$fromname);
}

function LocalSendMail($to, $subject,$body,$from="",$fromname="")
{
                //se nao for informado o remetente, recupera das configurações do sistema
		if(empty($from))
                {
                    $sql = "select nomeremetenteemail, emailremetente from lda_configuracao";
                    $rs = execQuery($sql);

                    $row = mysqli_fetch_array($rs);

                    $from = $row['emailremetente'];
                    $fromname = $row['nomeremetenteemail'];
                }
		$html = "<html>
					<body>
					$body
					</body>
				</html>";


		//$headers = "Content-Type: text/plain\r\n";
		$headers = "Content-type: text/html; charset=ISO-8859-1\r\n";
		$headers .= "Reply-To: $fromname <$from>\r\n";
		$headers .= "Return-Path: $fromname <$from>\r\n";
		$headers .= "From: $fromname <$from>\r\n";
		// $headers .= "Organization: Prefeitura do Natal\r\n";


		if (mail($to, $subject, $html, $headers)) {
			return true;
		} else {
			error_log("E-mail de confirma��o de cadastro n�o enviado. �ltima mensagem de erro:");
			$e = error_get_last();
			error_log($e["message"]);
			return false;
		}
}

//Function SendMail com phpMailer - Opcional
function PHPMailerSendMail($to, $subject, $body, $from="", $fromname=""){
    if (empty($from)) {
        $sql = "SELECT nomeremetenteemail, emailremetente FROM lda_configuracao";
        $rs = execQuery($sql);
        $row = mysqli_fetch_array($rs);
        $from = $row['emailremetente'];
        $fromname = $row['nomeremetenteemail'];
    }
    $html = "<html>
        <body>
            {$body}
        </body>
    </html>";

    $mail = (new Mail(Container::get('settingsMailer')))
    ->setFrom($from, $fromname)
    ->addTo($to)
    ->setSubject($subject)
    ->setBody($html, true, $body);

    if ($mail->send()) {
        return true;
    }


    error_log('E-mail de confirmação de cadastro não pôde ser enviado. Descrição do erro:');
    error_log($mail->getError());
    return false;
}

function sendMailAnexo($to, $subject,$body,$arquivos=array(),$from="",$fromname="",$cc="")
{
                //se nao for informado o remetente, recupera das configura��es do sistema
		if(empty($from))
                {
                    $sql = "select nomeremetenteemail, emailremetente from lda_configuracao";
                    $rs = execQuery($sql);

                    $row = mysqli_fetch_array($rs);

                    $from = $row['emailremetente'];
                    $fromname = $row['nomeremetenteemail'];
                }
		$html = "<html>
					<body>
					$body
					</body>
				</html>";


		$boundary = strtotime('NOW');

		$headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"".PHP_EOL;
		$headers .= "Reply-To: $fromname <$from>".PHP_EOL;
		$headers .= "Return-Path: $fromname <$from>".PHP_EOL;
		$headers .= "From: $fromname <$from>".PHP_EOL;
		$headers .= "Cc: $cc".PHP_EOL;
		$headers .= "MIME-Version: 1.0".PHP_EOL;
		$headers .= "Organization: Prefeitura do Natal".PHP_EOL;


		$msg = "--" . $boundary . PHP_EOL;
		$msg .= "Content-Type: text/html; charset=\"ISO-8859-1\"".PHP_EOL;
		$msg .= "Content-Transfer-Encoding: 8bit".PHP_EOL.PHP_EOL;
		$msg .= stripslashes($html).PHP_EOL;


		for($i=1; $i <= count($arquivos); $i++)
		{
			$finfo = finfo_open(FILEINFO_MIME_TYPE);

			$tipoarquivo = finfo_file($finfo, $arquivos['arquivo'][$i]);

			$msg .= "--" . $boundary . PHP_EOL;
			$msg .= "Content-Transfer-Encoding: base64".PHP_EOL;
			$msg .= "Content-Type: \"".$tipoarquivo."\"; name=\"".$arquivos['nome'][$i]."\"".PHP_EOL;
			$msg .= "Content-Disposition: attachment; filename=\"".$arquivos['nome'][$i]."\"".PHP_EOL.PHP_EOL;

			ob_start();
			   readfile($arquivos['arquivo'][$i]);
			   $enc = ob_get_contents();
			ob_end_clean();

			$msg_temp = base64_encode($enc). PHP_EOL;
			$tmp[1] = strlen($msg_temp);
			$tmp[2] = ceil($tmp[1]/76);

			for ($b = 0; $b <= $tmp[2]; $b++) {
				$tmp[3] = $b * 76;
				$msg .= substr($msg_temp, $tmp[3], 76) . PHP_EOL;
			}

			unset($msg_temp, $tmp, $enc);

		}
		return mail($to, $subject, $msg, $headers);


}

//registra na tabela de erros de login, a tentativa sem sucesso de acesso ao sistema
function setTentativaLogin($usuario)
{
	 $ipaddr = $_SERVER["REMOTE_ADDR"];
	 $sistema = SISTEMA_CODIGO;
	 $query = "insert into sis_errologin (sistema, usuario,ip) values('$sistema','$usuario','$ipaddr')";
	 execQuery($query) or die("Ocorreu um erro inesperado ao logar erro de entrada");
}

//exclui da tabela de erros de login, as tentativa sem sucesso de acesso ao sistema
function delTentativaLogin($usuario)
{
	 $sistema = SISTEMA_CODIGO;
	 $query = "delete from sis_errologin  where usuario='$usuario' and sistema = '$sistema'";
	 execQuery($query) or die("Ocorreu um erro inesperado ao excluir tentativas de acesso");
}

/*---------------------------------------------------------------------------
* verifica da existencia de uma tentativa de acesso, sem sucesso, ao sistema
* e retorna se deve ser usado o recaptcha para proxima tentativa de acesso
*---------------------------------------------------------------------------*/
function usaRecaptcha($usuario)
{
	if (empty($usuario)) return false;

	$sistema = SISTEMA_CODIGO;

	$query = "select * from sis_errologin
                  where usuario='$usuario' and sistema = '$sistema'";

	$rs = execQuery($query);

	//se houver tentativas registradas retorna true para exibir o controle recaptcha
	return (mysqli_num_rows($rs) >0) ;

}

//atualizar a unidade do usuario logado com a unidade selecionada no meu topo
function atualizaUnidadeUsuario($idsecretaria)
{

        $var = $_SESSION[SISTEMA_CODIGO];

        $sql="select nomesecretaria, siglasecretaria, idsecretaria
              from vw_secretariausuario und
              where md5(idsecretaria) = '$idsecretaria'
                    and idusuario = ".getSession('uid');

        $rs = execQuery($sql);

        if(mysqli_num_rows($rs)>0)
        {
            $row = mysqli_fetch_array($rs);

            $var["idsecretaria"] = $row['idsecretaria'];
            $var["siglasecretaria"] = $row['siglasecretaria'];
            $var["nomesecretaria"] = $row['nomesecretaria'];

            $_SESSION[SISTEMA_CODIGO] = $var;

            $rs = null;
            $row = null;

            return true;
        }
        else
        {
            return false;
        }

}
//funcao autentica�ao
function autentica($login, $pwd, $tipo)
{
	if (empty($login) or empty($pwd))
	{
		if(!empty($login))
			setTentativaLogin($login);

		return false;
	}


        $query = "select u.idusuario as id, u.nome, u.idsecretaria, s.sigla, s.nome as secretaria
                            from sis_usuario u, sis_secretaria s
                            where u.idsecretaria = s.idsecretaria
                                  and u.login='$login' and u.chave = '".md5($pwd)."'
                                  and u.status = 'A'";

        $rs = execQuery($query);

        if (mysqli_num_rows($rs) !=0)
        {
                $row = mysqli_fetch_array($rs);
        }
        else
        {
                //inclui tentativa de acesso ao sistema, para usar o recaptcha no proximo login
                setTentativaLogin($login);
                return false;
        }

	//exclui tentativas de acesso ao sistema do usuario (para evitar o recaptcha no proximo login)
	delTentativaLogin($login);

	$apelido = explode(" ",$row['nome']);

	$var = array();

	$var["uid"] = $row['id'];
	$var["nomeusuario"] = $row['nome'];
	$var["apelidousuario"] = $apelido[0];
        $var["idsecretaria"] = $row['idsecretaria'];
        $var["siglasecretaria"] = $row['sigla'];
        $var["nomesecretaria"] = $row['secretaria'];

	$_SESSION[SISTEMA_CODIGO] = $var;

        return true;

}
//fin autenticacao

/*pega o diretorio padrao para grava��o de arquivos
$sis = sistema para busca do diretorio na tabela de parametros
*/
function getDiretorio($sis = "lda"){

	$query = "select diretorioarquivos from lda_configuracao";

	$rs = execQuery($query);

	if (mysqli_num_rows($rs) !=0)
	{
		$row = mysqli_fetch_array($rs);
		$retorno = $row['diretorioarquivos'];
	}

	return $retorno;
}

/*paga o URL padrao para exibi��o de arquivos
$sis = sistema para busca do diretorio na tabela de parametros
*/
function getURL($sis = "lda"){

	$query = "select urlarquivos from lda_configuracao";

	$rs = execQuery($query);

	if (mysqli_num_rows($rs) !=0)
	{
		$row = mysqli_fetch_array($rs);
		$retorno = $row['urlarquivos'];
	}

	return $retorno;
}


function prepData($var) {
  $conn = db_open();

  if (get_magic_quotes_gpc()) {
    $var = stripslashes($var);
  }

  $retorno = mysqli_real_escape_string($conn, $var);

  db_close($conn);

  return $retorno;
}

function logger($msg) {
 $usuario = getSession("uid");
 $usuario = empty($usuario)?"SYSTEM":$usuario;

 // Ugly fix pra nao permitir salvar senha em banco.
 unset($_POST["senha"]);

 $mensagem = $msg;
 $datahora = "now()";
 $dados_post = prepData(serialize($_POST));
 $dados_get = prepData(serialize($_GET));
 $dados_session = prepData(serialize($_SESSION));
 $ipaddr = $_SERVER["REMOTE_ADDR"];

 $query = "insert into sis_log (usuario,mensagem,datahora,dados_get,dados_post,ipaddr) values('$usuario','$mensagem',$datahora,'$dados_get','$dados_post','$ipaddr')";
 execQuery($query) or die('Erro ao registrar informação no log');
}


function getErro($msg){
	//Exibe mensagem de erro passado pelas telas de manuten��o
	if (trim($msg) != "")
		echo "<script>alert('$msg'); </script>";
}

function getConfirmacao($msg,$funcaoConfirmacao){
	//Exibe mensagem de confirma��o passado pelas telas de manuten��o
	//funcaoConfirmacao -> fun��o javascript com o alert de confirma��o e procedimentos a serem seguidos.
	if (trim($msg) != "")
		echo "<script> $funcaoConfirmacao('$msg'); </script>";
}

function checkPerm($operacao,$retornapag=true) {
	$uid = getSession("uid");
	$query = "select permissao.idacao from sis_acao acao,sis_permissao permissao, sis_grupo g, sis_grupousuario gu
			  where acao.idacao=permissao.idacao
					and g.idgrupo = permissao.idgrupo
					and gu.idgrupo = g.idgrupo
				    and acao.status = 'A'
					and gu.idusuario = '$uid'
					and acao.operacao='$operacao'";

	$rs = execQuery($query);

	if (mysqli_num_rows($rs) !=0) {
		return true;
	} else {
		//se for passado o parametro false, nao retorna pagina de acesso negado, e sim o valor false.
		if ($retornapag){
			include "topo.php";
			echo "<h1>Acesso Negado</h1><br><center>
			<li>Voce nao tem permissao para acessar o modulo $operacao</li>
			<div align='center'><a href=\"#\" onclick=\"history.go(-1)\" >Voltar</a></p></center>";
			include "rodape.php";
			exit;
		}else{
			return false;
		}
	}
}
function Redirect($url) {
	Header("Location:$url");
}

function isauth($tipo="consumidor") {
	if(!isset($_SESSION[SISTEMA_CODIGO])) {
		Redirect(SITELNK."index/?t=".$tipo);
		exit;
	}
}

session_start();

?>
