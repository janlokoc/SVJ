<?xml version="1.0" encoding="Windows-1250"?>
<rsp:responsePack version="2.0" id="Usr01" state="ok" programVersion="14100.122 (23.9.2025)" ico="12345678" key="x" note="" xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:bnk="http://www.stormware.cz/schema/version_2/bank.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">
  <rsp:responsePackItem version="2.0" id="li1" state="ok">
    <lst:listInvoice version="2.0" dateTimeStamp="2026-11-20T10:00:00" dateValidFrom="2026-11-20" state="ok">
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:id>501</inv:id>
          <inv:invoiceType>receivable</inv:invoiceType>
          <inv:number><typ:numberRequested>PH26100</typ:numberRequested></inv:number>
          <inv:symVar>{VS}</inv:symVar>
          <inv:date>{M1}-01</inv:date>
          <inv:dateDue>{M1}-15</inv:dateDue>
          <inv:text>Předpis záloh {M1}</inv:text>
          <inv:liquidation><typ:amountHome>3450</typ:amountHome></inv:liquidation>
        </inv:invoiceHeader>
        <inv:invoiceDetail>
          <inv:invoiceItem><inv:text>Dlouhodobá záloha na opravy (fond oprav)</inv:text><inv:homeCurrency><typ:unitPrice>1500</typ:unitPrice><typ:price>1500</typ:price><typ:priceSum>1500</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
          <inv:invoiceItem><inv:text>Teplo</inv:text><inv:homeCurrency><typ:priceSum>1450</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
          <inv:invoiceItem><inv:text>Studená voda</inv:text><inv:homeCurrency><typ:priceSum>500</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
        </inv:invoiceDetail>
        <inv:invoiceSummary><inv:homeCurrency><typ:priceNone>3450</typ:priceNone><typ:priceLowSum>0</typ:priceLowSum><typ:priceHighSum>0</typ:priceHighSum><typ:round><typ:priceRound>0</typ:priceRound></typ:round></inv:homeCurrency></inv:invoiceSummary>
      </lst:invoice>
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:id>502</inv:id>
          <inv:invoiceType>receivable</inv:invoiceType>
          <inv:number><typ:numberRequested>PH26101</typ:numberRequested></inv:number>
          <inv:symVar>{VS}</inv:symVar>
          <inv:date>{M0}-01</inv:date>
          <inv:dateDue>{M0}-15</inv:dateDue>
          <inv:text>Předpis záloh {M0}</inv:text>
          <inv:liquidation><typ:amountHome>1000</typ:amountHome></inv:liquidation>
        </inv:invoiceHeader>
        <inv:invoiceDetail>
          <inv:invoiceItem><inv:text>Dlouhodobá záloha na opravy (fond oprav)</inv:text><inv:homeCurrency><typ:priceSum>1500</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
          <inv:invoiceItem><inv:text>Teplo</inv:text><inv:homeCurrency><typ:priceSum>1450</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
          <inv:invoiceItem><inv:text>Studená voda</inv:text><inv:homeCurrency><typ:priceSum>500</typ:priceSum></inv:homeCurrency></inv:invoiceItem>
        </inv:invoiceDetail>
        <inv:invoiceSummary><inv:homeCurrency><typ:priceNone>3450</typ:priceNone></inv:homeCurrency></inv:invoiceSummary>
      </lst:invoice>
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:id>503</inv:id>
          <inv:invoiceType>receivable</inv:invoiceType>
          <inv:number><typ:numberRequested>PH26102</typ:numberRequested></inv:number>
          <inv:symVar>99999</inv:symVar>
          <inv:date>{M0}-01</inv:date>
          <inv:dateDue>{M0}-15</inv:dateDue>
          <inv:text>Předpis neznámé jednotky</inv:text>
        </inv:invoiceHeader>
        <inv:invoiceSummary><inv:homeCurrency><typ:priceNone>2000</typ:priceNone></inv:homeCurrency></inv:invoiceSummary>
      </lst:invoice>
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:id>9</inv:id>
          <inv:invoiceType>issuedInvoice</inv:invoiceType>
          <inv:symVar>240100007</inv:symVar>
        </inv:invoiceHeader>
      </lst:invoice>
    </lst:listInvoice>
  </rsp:responsePackItem>
  <rsp:responsePackItem version="2.0" id="lb1" state="ok">
    <lst:listBank version="2.0" state="ok">
      <lst:bank version="2.0">
        <bnk:bankHeader>
          <bnk:id>27</bnk:id>
          <bnk:bankType>receipt</bnk:bankType>
          <bnk:number>KB10170001</bnk:number>
          <bnk:symVar>{VS}</bnk:symVar>
          <bnk:dateStatement>{M0}-10</bnk:dateStatement>
          <bnk:datePayment>{M0}-09</bnk:datePayment>
          <bnk:text>Úhrada zálohy</bnk:text>
        </bnk:bankHeader>
        <bnk:bankSummary><bnk:homeCurrency><typ:priceNone>1000</typ:priceNone><typ:priceLow>0</typ:priceLow><typ:priceHigh>0</typ:priceHigh></bnk:homeCurrency></bnk:bankSummary>
      </lst:bank>
      <lst:bank version="2.0">
        <bnk:bankHeader>
          <bnk:id>28</bnk:id>
          <bnk:bankType>expense</bnk:bankType>
          <bnk:symVar>{VS}</bnk:symVar>
          <bnk:datePayment>{M0}-09</bnk:datePayment>
        </bnk:bankHeader>
      </lst:bank>
    </lst:listBank>
  </rsp:responsePackItem>
</rsp:responsePack>
